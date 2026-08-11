/**
 * assets/js/modules/accounting-expenses.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — front-end for /modules/accounting/expenses.php.
 *
 * Talks only to /api/v1/expenses_controller.php. No inline SQL, no direct
 * treasury writes — every mutation goes through the controller which owns
 * the transaction + journal posting.
 * -----------------------------------------------------------------------------
 */
(function () {
    const API = '/api/v1/expenses_controller.php';
    const $ = (s, r=document) => r.querySelector(s);
    const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
    const flags = window.LPC_EXPENSES || {};

    // ---- Formatting helpers ------------------------------------------------
    const fmtF = (n) => new Intl.NumberFormat('fr-FR').format(Math.round(Number(n)||0)) + ' F';
    const fmtDate = (d) => {
        if (!d) return '—';
        const dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('fr-FR', { day:'2-digit', month:'short', year:'numeric' });
    };
    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // Tailwind-safe pill palette. Kept as a static map so Tailwind's JIT sees
    // every class it needs; interpolating class names would drop them.
    const CAT_PALETTE = {
        slate:   'bg-slate-100 text-slate-700',
        gray:    'bg-gray-100 text-gray-700',
        teal:    'bg-teal-100 text-teal-800',
        indigo:  'bg-indigo-100 text-indigo-800',
        sky:     'bg-sky-100 text-sky-800',
        amber:   'bg-amber-100 text-amber-800',
        orange:  'bg-orange-100 text-orange-800',
        rose:    'bg-rose-100 text-rose-800',
        pink:    'bg-pink-100 text-pink-800',
        emerald: 'bg-emerald-100 text-emerald-800',
    };
    const catPill = (name, color, icon) => {
        const pal = CAT_PALETTE[color] || CAT_PALETTE.slate;
        return `<span class="cat-pill ${pal}"><i class="fas ${icon || 'fa-tag'}"></i> ${escapeHtml(name)}</span>`;
    };

    // Bar: renders a budget consumption bar; > 100% is capped at 100% for the
    // visual but the label carries the true % so overshoot is visible.
    const budgetBar = (actual, budget) => {
        const b = Number(budget) || 0;
        const a = Number(actual) || 0;
        if (b <= 0) return `<div class="text-[11px] text-gray-400 font-bold">Aucun budget défini</div>`;
        const pct = (a / b) * 100;
        const cls = pct > 100 ? 'over' : (pct > 80 ? 'warn' : 'ok');
        return `
            <div class="flex justify-between text-[10px] font-black uppercase tracking-widest ${pct>100?'text-rose-700':'text-gray-500'} mb-1">
                <span>${fmtF(a)} / ${fmtF(b)}</span>
                <span>${pct.toFixed(0)}%</span>
            </div>
            <div class="bar-track"><div class="bar-fill ${cls}" style="width:${Math.min(100, pct)}%"></div></div>
        `;
    };

    // ---- API wrapper -------------------------------------------------------
    //
    // Callers pass the action name plus, optionally, extra query params — either
    // appended to the string ("dashboard&year=2026&month=8") or as a second
    // object argument. The string form is why this wrapper exists in this shape:
    // the previous version did
    //
    //     `${API}?action=${encodeURIComponent(action)}`
    //
    // which percent-encoded the separating "&" as well, so PHP received a single
    // parameter action="dashboard&year=2026&month=8" and every read fell through
    // to `jr('error', "Action inconnue: $action")`. That is the red toast on the
    // dashboard: the module never loaded any data at all. Only the action name
    // is encoded now; the rest of the query string is passed through intact.
    async function api(action, params = null, method = 'GET', body = null, isForm = false) {
        const [name, ...tail] = String(action).split('&');
        const qs = new URLSearchParams({ action: name });
        if (params && typeof params === 'object') {
            for (const [k, v] of Object.entries(params)) {
                if (v !== undefined && v !== null && v !== '') qs.set(k, v);
            }
        }
        let url = `${API}?${qs.toString()}`;
        if (tail.length) url += '&' + tail.join('&');

        const opts = { method, headers: { 'Accept': 'application/json' } };
        if (body) {
            if (isForm) { opts.body = body; }
            else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        }
        try {
            const r = await fetch(url, opts);
            // A 403 from Rbac or a 419 from Csrf still returns JSON, so parse
            // first and only fall back to the status line if that fails.
            let j;
            try { j = await r.json(); }
            catch { toast(`Erreur serveur (HTTP ${r.status}).`, 'error'); return null; }
            if (j.status !== 'success') {
                toast(j.message || 'Erreur', 'error');
                return null;
            }
            return j.data ?? true;
        } catch (e) {
            toast('Erreur réseau: ' + e.message, 'error');
            return null;
        }
    }

    /** POST helper — keeps the call sites readable now that api() takes params. */
    const apiPost = (action, body) => api(action, null, 'POST', body);

    // ---- Toast (minimal; app-wide LPC.toast may exist too) -----------------
    function toast(msg, kind='success') {
        if (window.LPC && LPC.toast) return LPC.toast(msg, kind);
        const el = document.createElement('div');
        const bg = kind === 'error' ? 'bg-rose-600' : (kind === 'warning' ? 'bg-amber-500' : 'bg-emerald-600');
        el.className = `${bg} text-white font-bold px-5 py-3 rounded-xl shadow-2xl fixed top-6 right-6 z-[100] text-sm animate-pulse`;
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3200);
    }

    // ---- Tab switcher ------------------------------------------------------
    window.switchExpenseTab = function (name) {
        $$('.tab-link').forEach(t => {
            t.classList.remove('border-lpc-dark','text-lpc-dark');
            t.classList.add('border-transparent','text-gray-500');
            t.setAttribute('aria-selected','false');
        });
        $$('.tab-content').forEach(c => c.classList.remove('active'));
        const btn = $('#tab-' + name);
        const cnt = $('#content-' + name);
        if (btn) { btn.classList.add('border-lpc-dark','text-lpc-dark'); btn.classList.remove('border-transparent','text-gray-500'); btn.setAttribute('aria-selected','true'); }
        if (cnt) cnt.classList.add('active');
        if (name === 'dashboard')   loadDashboard();
        if (name === 'list')        loadList();
        if (name === 'recurring')   loadRecurring();
        if (name === 'categories')  loadCategories();
    };

    window.closeModal = function (id) { const m = $('#' + id); if (m) m.classList.remove('open'); };
    window.openModal  = function (id) { const m = $('#' + id); if (m) m.classList.add('open'); };

    // ========================================================================
    //  DASHBOARD
    // ========================================================================
    let trendChart = null;

    async function loadDashboard() {
        const year  = $('#dash_year').value;
        const month = $('#dash_month').value;
        $('#dash_year_label').textContent = year;
        const d = await api('dashboard', { year, month });
        if (!d) return;
        $('#kpi_month').textContent        = fmtF(d.kpis.month);
        $('#kpi_ytd').textContent          = fmtF(d.kpis.ytd);
        $('#kpi_unpaid').textContent       = fmtF(d.kpis.unpaid);
        $('#kpi_unpaid_count').textContent = d.kpis.unpaid_count;
        $('#kpi_over').textContent         = d.kpis.over_budget;

        // Trend chart
        const ctx = $('#trendChart').getContext('2d');
        if (trendChart) trendChart.destroy();
        trendChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'],
                datasets: [{
                    label: 'Dépenses',
                    data: d.trend,
                    backgroundColor: (c) => (c.dataIndex+1) === Number(month) ? '#005A2B' : '#8CC63F',
                    borderRadius: 6,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (t) => fmtF(t.parsed.y) } } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (v) => new Intl.NumberFormat('fr-FR', { notation:'compact' }).format(v) + ' F' } },
                    x: { grid: { display: false } },
                }
            }
        });

        // Recent activity list
        const recent = d.recent.map(r => `
            <div class="px-5 py-3 flex items-center justify-between gap-3 hover:bg-gray-50 transition-colors">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-0.5">
                        ${catPill(r.category_name, r.category_color, r.category_icon)}
                    </div>
                    <p class="text-sm font-bold text-gray-800 truncate">${escapeHtml(r.title)}</p>
                    <p class="text-[10px] text-gray-500 font-bold uppercase">${fmtDate(r.expense_date)} · ${escapeHtml(r.reference)}</p>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-sm font-black text-gray-900">${fmtF(r.amount)}</p>
                    <span class="text-[9px] font-black uppercase tracking-widest ${r.payment_status==='paid'?'text-emerald-600':'text-amber-600'}">
                        ${r.payment_status==='paid'?'Payé':'En attente'}
                    </span>
                </div>
            </div>
        `).join('') || `<div class="empty-state text-xs"><i class="fas fa-inbox"></i><br>Aucune activité récente</div>`;
        $('#dash_recent').innerHTML = recent;

        // Category breakdown
        const brk = d.breakdown.map(b => `
            <div class="bg-slate-50 border border-gray-200 rounded-xl p-4">
                <div class="flex items-center justify-between mb-3">
                    ${catPill(b.name, b.color, b.icon)}
                    <span class="text-lg font-black text-gray-900">${fmtF(b.actual)}</span>
                </div>
                ${budgetBar(b.actual, b.budget)}
            </div>
        `).join('') || `<div class="empty-state md:col-span-2 xl:col-span-3"><i class="fas fa-tags"></i><br>Aucune catégorie active</div>`;
        $('#dash_breakdown').innerHTML = brk;
    }

    // ========================================================================
    //  LIST
    // ========================================================================
    let listMeta = null;

    async function loadListMeta() {
        if (listMeta) return listMeta;
        const meta = await api('form_meta');
        // api() returns null on any error (403, 419, network). Bailing out here
        // instead of assigning keeps every caller's `meta.categories` from
        // throwing a TypeError that would silently kill the rest of the handler.
        if (!meta) return null;
        listMeta = meta;

        // Repopulating the filter <select> wipes whatever the user had chosen,
        // and this function is re-entered every time listMeta is invalidated
        // after a save. Preserve the selection across the rebuild.
        const cat = $('#list_cat');
        if (cat) {
            const previous = cat.value;
            cat.innerHTML = '<option value="">Toutes catégories</option>' +
                listMeta.categories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
            if (previous && cat.querySelector(`option[value="${previous}"]`)) cat.value = previous;
        }
        return listMeta;
    }

    async function loadList(page = 1) {
        if (!await loadListMeta()) return;
        const start = $('#list_start').value || `${new Date().getFullYear()}-01-01`;
        const end   = $('#list_end').value   || `${new Date().getFullYear()}-12-31`;
        const cat   = $('#list_cat').value;
        const stat  = $('#list_status').value;
        const q     = $('#list_q').value.trim();
        const d = await api('list', {
            start, end, page, per_page: 20,
            category_id: cat, status: stat, q,
        });
        if (!d || !Array.isArray(d.rows)) return;

        const rows = d.rows.map(r => {
            const paid = r.payment_status === 'paid';
            const statusPill = paid
                ? `<span class="cat-pill bg-emerald-100 text-emerald-800"><i class="fas fa-check"></i> Payé</span>`
                : `<span class="cat-pill bg-amber-100 text-amber-800"><i class="fas fa-clock"></i> En attente</span>`;
            const actions = [];
            if (!paid && flags.can_pay)     actions.push(`<button onclick="openPayModal(${r.id}, ${r.amount})" title="Marquer payée" class="p-2 text-emerald-600 hover:bg-emerald-50 rounded-lg"><i class="fas fa-check-circle"></i></button>`);
            if (!paid && flags.can_edit)    actions.push(`<button onclick="editExpense(${r.id})" title="Modifier" class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-pen"></i></button>`);
            if (flags.can_delete)           actions.push(`<button onclick="deleteExpense(${r.id})" title="Supprimer" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg"><i class="fas fa-trash"></i></button>`);
            return `
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="py-3 px-4 text-gray-700 font-bold text-xs whitespace-nowrap">${fmtDate(r.expense_date)}</td>
                    <td class="py-3 px-4 text-gray-500 font-black text-xs">${escapeHtml(r.reference)}</td>
                    <td class="py-3 px-4 text-gray-900 font-bold">${escapeHtml(r.title)}
                        ${r.supplier_name ? `<div class="text-[11px] text-gray-500 font-medium mt-0.5"><i class="fas fa-user mr-1"></i>${escapeHtml(r.supplier_name)}</div>` : ''}
                    </td>
                    <td class="py-3 px-4">${catPill(r.category_name, r.category_color, r.category_icon)}</td>
                    <td class="py-3 px-4 text-xs font-bold text-gray-600">${r.treasury_name ? escapeHtml(r.treasury_name) : '<span class="text-gray-300">—</span>'}</td>
                    <td class="py-3 px-4 text-right font-black text-lpc-dark">${fmtF(r.amount)}</td>
                    <td class="py-3 px-4 text-center">${statusPill}</td>
                    <td class="py-3 px-4 text-center text-xs font-bold">
                        <button onclick="openAttachments(${r.id})"
                                title="Pièces jointes / reçus"
                                class="px-2 py-1 rounded-lg hover:bg-gray-100 ${r.attachment_count > 0 ? 'text-lpc-dark' : 'text-gray-400'}">
                            <i class="fas fa-paperclip"></i> ${r.attachment_count > 0 ? r.attachment_count : ''}
                        </button>
                    </td>
                    <td class="py-3 px-4 text-right whitespace-nowrap">${actions.join('') || '<span class="text-gray-300 text-xs">—</span>'}</td>
                </tr>
            `;
        }).join('') || `<tr><td colspan="9" class="empty-state"><i class="fas fa-inbox"></i><br>Aucune dépense sur cette période</td></tr>`;
        $('#list_body').innerHTML = rows;

        // Paginator
        const p = d.pagination;
        $('#list_paginator').innerHTML = `
            <span>${p.total} dépense(s) — page ${p.page} / ${p.total_pages || 1}</span>
            <div class="flex gap-2">
                <button ${p.has_prev?'':'disabled'} onclick="loadList(${p.page-1})" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"><i class="fas fa-chevron-left"></i></button>
                <button ${p.has_next?'':'disabled'} onclick="loadList(${p.page+1})" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"><i class="fas fa-chevron-right"></i></button>
            </div>
        `;
    }
    window.loadList = loadList;

    // ========================================================================
    //  EXPENSE MODAL — create/edit
    // ========================================================================
    async function openExpenseModal(id = null) {
        if (!await loadListMeta()) return;
        $('#exp_id').value = id ?? '';
        $('#exp_modal_title').textContent = id ? 'Modifier la dépense' : 'Nouvelle dépense';

        // Populate dropdowns
        $('#exp_category').innerHTML = listMeta.categories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
        $('#exp_treasury').innerHTML = '<option value="">Choisir…</option>' + listMeta.accounts.map(a =>
            `<option value="${a.id}">${escapeHtml(a.name)} — ${fmtF(a.balance)}</option>`).join('');
        $('#exp_supplier').innerHTML = '<option value="">— aucun —</option>' + listMeta.suppliers.map(s =>
            `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
        $('#exp_date').value = new Date().toISOString().slice(0,10);
        $('#exp_amount').value = '';
        $('#exp_title').value = '';
        $('#exp_desc').value = '';
        $('#exp_status').value = 'paid';
        $('#budget_probe').classList.add('hidden');

        if (id) {
            const d = await api('get', { id });
            if (!d) return;
            $('#exp_title').value    = d.title || '';
            $('#exp_desc').value     = d.description || '';
            $('#exp_category').value = d.category_id;
            $('#exp_amount').value   = d.amount;
            $('#exp_date').value     = d.expense_date;
            $('#exp_status').value   = d.payment_status;
            $('#exp_treasury').value = d.treasury_account_id || '';
            $('#exp_supplier').value = d.supplier_id || '';
        }
        probeBudget();
        openModal('modal-expense');
    }
    window.openExpenseModal = openExpenseModal;
    window.editExpense = openExpenseModal;

    async function probeBudget() {
        const cat  = $('#exp_category').value;
        const date = $('#exp_date').value;
        const amt  = Number($('#exp_amount').value) || 0;
        if (!cat || !date) return;
        // exclude_id keeps the row being edited out of "déjà consommé". Without
        // it, reopening a saved expense counts its own amount twice and the
        // banner reports a budget overrun that does not exist.
        const d = await api('budget_probe', {
            category_id: cat,
            expense_date: date,
            exclude_id: Number($('#exp_id').value) || 0,
        });
        if (!d) return;
        const el = $('#budget_probe');
        if (d.budget <= 0) { el.classList.add('hidden'); return; }
        const projected = d.spent + amt;
        const pct = (projected / d.budget) * 100;
        const remaining = d.budget - d.spent;
        if (projected > d.budget) {
            el.className = 'rounded-xl p-4 text-sm bg-rose-50 border border-rose-200 text-rose-800';
            el.innerHTML = `<i class="fas fa-triangle-exclamation mr-2"></i>
                <b>Dépassement budgétaire — ${(pct-100).toFixed(0)}% au-dessus du plafond mensuel.</b><br>
                Consommé: ${fmtF(d.spent)} / Budget: ${fmtF(d.budget)}. Cette dépense sera acceptée mais dépassera le budget de ${fmtF(projected - d.budget)}.`;
        } else if (pct > 80) {
            el.className = 'rounded-xl p-4 text-sm bg-amber-50 border border-amber-200 text-amber-800';
            el.innerHTML = `<i class="fas fa-hourglass-half mr-2"></i>
                Attention : cette dépense portera la consommation à ${pct.toFixed(0)}% du budget mensuel (reste ${fmtF(remaining - amt)}).`;
        } else {
            el.className = 'rounded-xl p-4 text-sm bg-emerald-50 border border-emerald-200 text-emerald-800';
            el.innerHTML = `<i class="fas fa-circle-check mr-2"></i>
                Dans le budget. Consommation projetée : ${pct.toFixed(0)}% (reste ${fmtF(remaining - amt)}).`;
        }
        el.classList.remove('hidden');
    }
    window.probeBudget = probeBudget;

    let savingExpense = false;
    async function submitExpense() {
        if (savingExpense) return;              // double-click guard
        const title = $('#exp_title').value.trim();
        const amount = Number($('#exp_amount').value);
        const treasury = Number($('#exp_treasury').value) || null;
        // Validate client-side too: the controller rejects these, but a toast
        // beats a round-trip and the modal stays open with the field in view.
        if (!title)                     return toast('Titre requis.', 'error');
        if (!Number($('#exp_category').value)) return toast('Catégorie requise.', 'error');
        if (!(amount > 0))              return toast('Le montant doit être supérieur à 0.', 'error');
        if (!treasury)                  return toast('Compte de trésorerie requis.', 'error');

        savingExpense = true;
        const btn = $('#btn-save-expense');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement…';
        const payload = {
            id: Number($('#exp_id').value) || 0,
            title,
            description: $('#exp_desc').value.trim(),
            category_id: Number($('#exp_category').value),
            supplier_id: Number($('#exp_supplier').value) || null,
            amount,
            expense_date: $('#exp_date').value,
            payment_status: $('#exp_status').value,
            treasury_account_id: treasury,
            // Settle on the date the charge was incurred, not on "today". Saving
            // a backdated expense as paid used to stamp payment_date = today,
            // which pushed the journal entry into the wrong month.
            payment_date: $('#exp_status').value === 'paid' ? $('#exp_date').value : null,
        };
        const r = await apiPost('save', payload);
        savingExpense = false;
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
        if (!r) return;
        toast('Dépense enregistrée.', 'success');
        closeModal('modal-expense');
        // Refresh whichever tab is active.
        if ($('#content-dashboard').classList.contains('active')) loadDashboard();
        if ($('#content-list').classList.contains('active'))      loadList();
        // Invalidate meta so treasury balances refresh on next open.
        listMeta = null;
    }
    window.submitExpense = submitExpense;

    async function deleteExpense(id) {
        if (!confirm('Supprimer cette dépense ? Si elle est comptabilisée, une écriture d\'extourne sera créée automatiquement.')) return;
        const r = await apiPost('delete', { id });
        if (!r) return;
        toast('Dépense supprimée.', 'success');
        loadList();
        if ($('#content-dashboard').classList.contains('active')) loadDashboard();
    }
    window.deleteExpense = deleteExpense;

    // ---- Pay modal ---------------------------------------------------------
    window.openPayModal = async function (id, amount) {
        if (!await loadListMeta()) return;
        $('#pay_id').value = id;
        $('#pay_amount_label').textContent = fmtF(amount);
        $('#pay_treasury').innerHTML = '<option value="">Choisir…</option>' +
            listMeta.accounts.map(a => `<option value="${a.id}">${escapeHtml(a.name)} — ${fmtF(a.balance)}</option>`).join('');
        $('#pay_date').value = new Date().toISOString().slice(0,10);
        openModal('modal-pay');
    };
    let payingExpense = false;
    window.submitPay = async function () {
        if (payingExpense) return;              // double-click guard: a second
        // click here would debit the treasury account twice and post two JEs.
        const id = Number($('#pay_id').value);
        const t  = Number($('#pay_treasury').value);
        const d  = $('#pay_date').value;
        if (!t) return toast('Compte requis.', 'error');
        payingExpense = true;
        const r = await apiPost('mark_paid', { id, treasury_account_id: t, payment_date: d });
        payingExpense = false;
        if (!r) return;
        toast('Dépense marquée payée et comptabilisée.', 'success');
        closeModal('modal-pay');
        loadList();
        if ($('#content-dashboard').classList.contains('active')) loadDashboard();
        listMeta = null;
    };

    // ========================================================================
    //  ATTACHMENTS (receipts / invoices)
    //
    // expenses_controller has shipped upload_attachment / delete_attachment
    // since migration 053 and the list has always rendered an attachment count,
    // but nothing in the UI ever called either endpoint — there was no way to
    // attach a receipt. This panel is that missing half.
    //
    // It is reachable from every row, including posted ones: an expense locks
    // for editing once it has a journal entry, but a receipt arriving late is
    // normal and must still be filable against it.
    // ========================================================================
    let attachExpenseId = 0;

    // Takes only the id. The reference is read off the `get` response rather
    // than interpolated into the onclick attribute — a string placed inside
    // onclick="…" is HTML-decoded before the JS parser sees it, so escapeHtml()
    // is the wrong escaper there (&#39; decodes straight back to a quote and
    // breaks out of the literal). Nothing user-controlled reaches the markup.
    window.openAttachments = async function (id) {
        attachExpenseId = Number(id) || 0;
        if (!attachExpenseId) return;
        $('#att_ref').textContent = '';
        $('#att_list').innerHTML = `<div class="empty-state text-xs"><i class="fas fa-spinner fa-spin"></i><br>Chargement…</div>`;
        const input = $('#att_file');
        if (input) input.value = '';
        openModal('modal-attachments');
        await refreshAttachments();
    };

    async function refreshAttachments() {
        const d = await api('get', { id: attachExpenseId });
        if (!d) return;
        // textContent, so no escaping concern.
        $('#att_ref').textContent = d.reference || '';
        const items = Array.isArray(d.attachments) ? d.attachments : [];
        $('#att_list').innerHTML = items.map(a => {
            const isImg = String(a.mime_type || '').startsWith('image/');
            const href  = '/' + String(a.stored_path || '').replace(/^\/+/, '');
            return `
            <div class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                <i class="fas ${isImg ? 'fa-file-image text-sky-600' : 'fa-file-pdf text-rose-600'} text-lg w-5 text-center"></i>
                <div class="min-w-0 flex-1">
                    <a href="${escapeHtml(href)}" target="_blank" rel="noopener"
                       class="text-sm font-bold text-gray-800 hover:text-lpc-dark hover:underline truncate block">${escapeHtml(a.filename)}</a>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                        ${fmtBytes(a.size_bytes)} · ${escapeHtml(String(a.uploaded_at || '').slice(0, 10))}
                    </p>
                </div>
                ${flags.can_edit ? `<button onclick="deleteAttachment(${a.id})" title="Supprimer"
                        class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg shrink-0"><i class="fas fa-trash"></i></button>` : ''}
            </div>`;
        }).join('') || `<div class="empty-state text-xs"><i class="fas fa-receipt"></i><br>Aucun reçu attaché</div>`;
    }

    const fmtBytes = (n) => {
        const b = Number(n) || 0;
        if (b < 1024) return b + ' o';
        if (b < 1024 * 1024) return (b / 1024).toFixed(0) + ' Ko';
        return (b / 1024 / 1024).toFixed(1) + ' Mo';
    };

    let uploadingAttachment = false;
    window.uploadAttachment = async function () {
        if (uploadingAttachment) return;
        const input = $('#att_file');
        const file  = input && input.files && input.files[0];
        if (!file) return toast('Choisissez un fichier.', 'error');
        if (file.size > 10 * 1024 * 1024) return toast('Fichier > 10 Mo.', 'error');

        uploadingAttachment = true;
        const btn = $('#btn-upload-attachment');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi…';

        const fd = new FormData();
        fd.append('expense_id', attachExpenseId);
        fd.append('file', file);
        // isForm = true: let the browser set the multipart boundary itself.
        const r = await api('upload_attachment', null, 'POST', fd, true);

        uploadingAttachment = false;
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload"></i> Téléverser';
        if (!r) return;
        input.value = '';
        toast('Reçu ajouté.', 'success');
        await refreshAttachments();
        loadList();
    };

    window.deleteAttachment = async function (id) {
        if (!confirm('Supprimer cette pièce jointe ?')) return;
        const r = await apiPost('delete_attachment', { id });
        if (!r) return;
        toast('Pièce jointe supprimée.', 'success');
        await refreshAttachments();
        loadList();
    };

    // ========================================================================
    //  CATEGORIES
    // ========================================================================
    async function loadCategories() {
        const d = await api('categories');
        if (!Array.isArray(d)) return;
        const rows = d.map(c => `
            <tr class="hover:bg-gray-50">
                <td class="py-3 px-4">${catPill(c.name, c.color, c.icon)}
                    ${c.is_system ? '<span class="ml-2 text-[9px] font-black uppercase text-gray-400">système</span>' : ''}
                </td>
                <td class="py-3 px-4 text-xs font-bold text-gray-700">
                    ${c.coa_code ? `<span class="font-black text-lpc-dark">${escapeHtml(c.coa_code)}</span> — ${escapeHtml(c.coa_name)}` : '<span class="text-rose-600"><i class="fas fa-triangle-exclamation"></i> non mappé</span>'}
                </td>
                <td class="py-3 px-4 text-center text-xs font-bold text-gray-600">${c.usage_count} dép.</td>
                <td class="py-3 px-4 text-center">${c.is_active
                    ? '<span class="cat-pill bg-emerald-100 text-emerald-800">Active</span>'
                    : '<span class="cat-pill bg-gray-100 text-gray-500">Inactive</span>'}</td>
                <td class="py-3 px-4 text-right whitespace-nowrap">
                    <button onclick="editCategory(${c.id})" class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-pen"></i></button>
                    ${c.is_system ? '' : `<button onclick="deleteCategory(${c.id})" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg"><i class="fas fa-trash"></i></button>`}
                </td>
            </tr>
        `).join('');
        $('#cat_body').innerHTML = rows || `<tr><td colspan="5" class="empty-state">Aucune catégorie</td></tr>`;
    }

    window.openCategoryModal = async function () {
        const meta = await api('form_meta');
        if (!meta) return;
        $('#cat_id').value = ''; $('#cat_name').value=''; $('#cat_icon').value='fa-tag';
        $('#cat_color').value='slate'; $('#cat_active').checked=true;
        $('#cat_coa').innerHTML = '<option value="">— aucun —</option>' + meta.ohada_expense.map(o =>
            `<option value="${o.id}">${escapeHtml(o.code)} — ${escapeHtml(o.name)}</option>`).join('');
        $('#cat_modal_title').textContent = 'Nouvelle catégorie';
        openModal('modal-category');
    };

    window.editCategory = async function (id) {
        const [cats, meta] = await Promise.all([api('categories'), api('form_meta')]);
        if (!cats || !meta) return;
        const c = cats.find(x => Number(x.id) === Number(id));
        if (!c) return;
        $('#cat_id').value = c.id; $('#cat_name').value=c.name;
        $('#cat_icon').value=c.icon||''; $('#cat_color').value=c.color||'slate';
        $('#cat_active').checked = Number(c.is_active) === 1;
        $('#cat_coa').innerHTML = '<option value="">— aucun —</option>' + meta.ohada_expense.map(o =>
            `<option value="${o.id}" ${Number(o.id)===Number(c.coa_account_id)?'selected':''}>${escapeHtml(o.code)} — ${escapeHtml(o.name)}</option>`).join('');
        $('#cat_modal_title').textContent = 'Modifier la catégorie';
        openModal('modal-category');
    };

    window.submitCategory = async function () {
        if (!$('#cat_name').value.trim()) return toast('Nom requis.', 'error');
        // The controller now refuses a category with no OHADA account: without
        // one, every expense filed under it fails at posting time instead of
        // at category-creation time, which is far harder to diagnose.
        if (!Number($('#cat_coa').value)) return toast('Compte OHADA requis.', 'error');
        const payload = {
            id: Number($('#cat_id').value) || 0,
            name: $('#cat_name').value.trim(),
            coa_account_id: Number($('#cat_coa').value) || null,
            icon: $('#cat_icon').value.trim(),
            color: $('#cat_color').value,
            is_active: $('#cat_active').checked ? 1 : 0,
        };
        const r = await apiPost('save_category', payload);
        if (!r) return;
        toast('Catégorie enregistrée.');
        closeModal('modal-category');
        loadCategories(); listMeta = null;
    };
    window.deleteCategory = async function (id) {
        if (!confirm('Supprimer cette catégorie ?')) return;
        const r = await apiPost('delete_category', { id });
        if (!r) return;
        toast('Catégorie supprimée.'); loadCategories();
    };

    // ========================================================================
    //  RECURRING
    // ========================================================================
    async function loadRecurring() {
        const d = await api('recurring');
        if (!Array.isArray(d)) return;
        const rows = d.map(r => `
            <tr class="hover:bg-gray-50">
                <td class="py-3 px-4 font-bold text-gray-800">${escapeHtml(r.name)}</td>
                <td class="py-3 px-4">${catPill(r.category_name, r.category_color, r.category_icon)}</td>
                <td class="py-3 px-4 text-xs font-bold text-gray-600">${r.treasury_name ? escapeHtml(r.treasury_name) : '<span class="text-gray-300">—</span>'}</td>
                <td class="py-3 px-4 text-right font-black text-lpc-dark">${fmtF(r.amount)}</td>
                <td class="py-3 px-4 text-center text-sm font-bold">Le ${r.day_of_month}</td>
                <td class="py-3 px-4 text-center text-xs text-gray-500 font-bold">${r.last_generated_date ? fmtDate(r.last_generated_date) : '—'}</td>
                <td class="py-3 px-4 text-center">${Number(r.is_active)===1
                    ? '<span class="cat-pill bg-emerald-100 text-emerald-800"><i class="fas fa-play"></i> Actif</span>'
                    : '<span class="cat-pill bg-gray-100 text-gray-500"><i class="fas fa-pause"></i> Pausé</span>'}</td>
                <td class="py-3 px-4 text-right whitespace-nowrap">
                    <button onclick="toggleRecurring(${r.id})" title="Activer/Pauser" class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-power-off"></i></button>
                    <button onclick="editRecurring(${r.id})" class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg"><i class="fas fa-pen"></i></button>
                    <button onclick="deleteRecurring(${r.id})" class="p-2 text-rose-600 hover:bg-rose-50 rounded-lg"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `).join('');
        $('#recur_body').innerHTML = rows || `<tr><td colspan="8" class="empty-state">Aucun modèle récurrent</td></tr>`;
    }

    window.openRecurringModal = async function () {
        const meta = await api('form_meta');
        if (!meta) return;
        $('#rec_id').value=''; $('#rec_name').value=''; $('#rec_amount').value=''; $('#rec_dom').value='1'; $('#rec_notes').value='';
        $('#rec_category').innerHTML = meta.categories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
        $('#rec_treasury').innerHTML = '<option value="">— aucun —</option>' + meta.accounts.map(a =>
            `<option value="${a.id}">${escapeHtml(a.name)}</option>`).join('');
        $('#rec_supplier').innerHTML = '<option value="">— aucun —</option>' + meta.suppliers.map(s =>
            `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
        $('#rec_modal_title').textContent = 'Nouveau modèle récurrent';
        openModal('modal-recurring');
    };
    window.editRecurring = async function (id) {
        const [list, meta] = await Promise.all([api('recurring'), api('form_meta')]);
        if (!list || !meta) return;
        const r = list.find(x => Number(x.id) === Number(id));
        if (!r) return;
        $('#rec_id').value = r.id; $('#rec_name').value=r.name; $('#rec_amount').value=r.amount;
        $('#rec_dom').value=r.day_of_month; $('#rec_notes').value=r.notes||'';
        $('#rec_category').innerHTML = meta.categories.map(c => `<option value="${c.id}" ${Number(c.id)===Number(r.category_id)?'selected':''}>${escapeHtml(c.name)}</option>`).join('');
        $('#rec_treasury').innerHTML = '<option value="">— aucun —</option>' + meta.accounts.map(a =>
            `<option value="${a.id}" ${Number(a.id)===Number(r.treasury_account_id)?'selected':''}>${escapeHtml(a.name)}</option>`).join('');
        $('#rec_supplier').innerHTML = '<option value="">— aucun —</option>' + meta.suppliers.map(s =>
            `<option value="${s.id}" ${Number(s.id)===Number(r.supplier_id)?'selected':''}>${escapeHtml(s.name)}</option>`).join('');
        $('#rec_modal_title').textContent = 'Modifier le modèle';
        openModal('modal-recurring');
    };
    window.submitRecurring = async function () {
        const payload = {
            id: Number($('#rec_id').value) || 0,
            name: $('#rec_name').value.trim(),
            category_id: Number($('#rec_category').value),
            supplier_id: Number($('#rec_supplier').value) || null,
            treasury_account_id: Number($('#rec_treasury').value) || null,
            amount: Number($('#rec_amount').value),
            day_of_month: Number($('#rec_dom').value),
            notes: $('#rec_notes').value.trim(),
        };
        const r = await apiPost('save_recurring', payload);
        if (!r) return;
        toast('Modèle enregistré.'); closeModal('modal-recurring'); loadRecurring();
    };
    window.toggleRecurring = async function (id) { const r = await apiPost('toggle_recurring', { id }); if (r) { toast('OK'); loadRecurring(); } };
    window.deleteRecurring = async function (id) {
        if (!confirm('Supprimer ce modèle ?')) return;
        const r = await apiPost('delete_recurring', { id }); if (r) { toast('Supprimé.'); loadRecurring(); }
    };

    // ========================================================================
    //  BOOT
    // ========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        // Default date range: whole current year.
        $('#list_start').value = `${new Date().getFullYear()}-01-01`;
        $('#list_end').value   = `${new Date().getFullYear()}-12-31`;

        // Wire the filter inputs. Debounce the free-text search to avoid a
        // request per keystroke on slow connections.
        let t;
        $('#list_q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => loadList(1), 350); });
        ['change'].forEach(ev => {
            ['#list_cat','#list_status','#list_start','#list_end'].forEach(sel => $(sel).addEventListener(ev, () => loadList(1)));
        });
        ['#dash_month','#dash_year'].forEach(sel => $(sel).addEventListener('change', loadDashboard));

        loadDashboard();

        // Deep-link support: /modules/accounting/expenses.php#recurring
        const EXP_VALID_TABS = ['dashboard', 'list', 'recurring', 'categories'];
        const hash = (window.location.hash || '').replace('#', '');
        if (EXP_VALID_TABS.includes(hash) && hash !== 'dashboard') window.switchExpenseTab(hash);
    });

})();
