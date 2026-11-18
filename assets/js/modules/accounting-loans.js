/**
 * assets/js/modules/accounting-loans.js
 * -----------------------------------------------------------------------------
 * Trésorerie & Banque → « Emprunts & Financements » (the owner's decision 15,
 * 18/11/2026). What LPC owes its lenders and what its borrowers owe LPC.
 *
 *   · the CF, the Comptable and the DG record a loan (LPC borrows or lends),
 *     a counterparty, a repayment of any amount;
 *   · the DG approves or refuses each: nothing posts before (the API refuses
 *     anyone else, Loans.php posts on approval only);
 *   · interest months post by themselves on approved terms (Loans::runDue,
 *     run by the API each time this tab loads).
 * accounting-cashflow.js calls window.LpcLoans.load() when the tab opens.
 * API: /api/v1/loans_controller.php. The CSRF header is added by lpc-rbac.js.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    const API = '/api/v1/loans_controller.php';
    const $root = document.getElementById('loans-root');
    if (!$root) return;

    let D = null;           // the last read
    const OPEN = new Set(); // loans whose detail is unfolded

    const de = (n) => (/^[aeiouyhàâéèêîôû]/i.test(String(n || '')) ? "d'" : 'de ') + n;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const F = (n) => Math.round(+n || 0).toLocaleString('fr-FR').replace(/ /g, ' ');
    const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const rate = (r) => String(+r).replace('.', ',');
    const terms = (l) => {
        const t = l.interest_kind === 'simple' ? `${rate(l.monthly_rate)} %/mois sur le restant dû`
            : l.interest_kind === 'compound' ? `${rate(l.monthly_rate)} %/mois, composé` : 'Sans intérêt';
        return +l.fee_amount > 0 ? `${t} · frais ${F(l.fee_amount)} F` : t;
    };
    const STATUS = {
        pending:  ['En attente du DG', 'bg-amber-50 text-amber-800 border-amber-200'],
        approved: ['En cours', 'bg-emerald-50 text-emerald-800 border-emerald-200'],
        closed:   ['Soldé', 'bg-gray-100 text-gray-600 border-gray-200'],
        rejected: ['Refusé', 'bg-rose-50 text-rose-700 border-rose-200'],
    };
    const badge = (s) => `<span class="inline-block px-2 py-0.5 rounded-full border text-[10px] font-black uppercase tracking-wider ${STATUS[s][1]}">${STATUS[s][0]}</span>`;

    function alertBox(msg) { (window.LPC?.modal?.alert || window.alert)(msg); }

    async function post(payload) {
        try {
            const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const r = await res.json();
            if (r.status !== 'success') { alertBox(r.message || 'Erreur serveur.'); return null; }
            return r;
        } catch (e) { alertBox('Erreur réseau : ' + e.message); return null; }
    }

    async function load() {
        $root.innerHTML = '<p class="text-sm text-gray-400 font-bold">Chargement…</p>';
        try {
            const r = await (await fetch(`${API}?action=read`)).json();
            if (r.status !== 'success') { $root.innerHTML = `<p class="text-sm text-rose-700 font-bold">${esc(r.message || 'Erreur de chargement.')}</p>`; return; }
            D = r.data;
            render();
        } catch (e) {
            $root.innerHTML = `<p class="text-sm text-rose-700 font-bold">Erreur de chargement : ${esc(e.message)}</p>`;
        }
    }

    // ------------------------------------------------------------------ the tab
    function render() {
        const s = D.summary;
        const pend = [];
        D.loans.forEach(l => {
            if (l.status === 'pending') pend.push({ type: 'loan', l });
            l.movements.forEach(m => { if (m.kind === 'repayment' && m.status === 'pending') pend.push({ type: 'repayment', l, m }); });
        });

        $root.innerHTML = `
            <div class="flex flex-wrap justify-between items-start gap-4">
                <div>
                    <h2 class="font-black text-gray-800 uppercase tracking-widest text-sm">Emprunts &amp; Financements</h2>
                    <p class="text-xs text-gray-500 font-bold mt-1 max-w-2xl">Ce que LPC doit à ses prêteurs et ce que ses emprunteurs lui doivent. Chaque prêteur ou emprunteur a son propre compte. Rien n'est comptabilisé avant l'approbation du DG.</p>
                </div>
                ${D.can.record ? `<button type="button" data-act="new" class="bg-treasury-dark hover:bg-green-800 text-white px-5 py-2 rounded-xl font-bold text-xs shadow-md"><i class="fas fa-plus mr-1"></i> Nouveau financement</button>` : ''}
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                ${card('LPC doit (emprunts)', F(s.borrowed) + ' F', 'fa-arrow-down text-rose-500', 'capital et intérêts restant dus')}
                ${card('On doit à LPC (prêts)', F(s.lent) + ' F', 'fa-arrow-up text-emerald-600', 'capital et intérêts à recevoir')}
                ${card('En attente du DG', String(s.pending), 'fa-user-check text-amber-500', s.pending ? 'financements et remboursements à approuver' : 'rien à approuver')}
                ${card('Prochaine échéance', s.next_due ? s.next_due.date_fr : '—', 'fa-calendar-alt text-blue-500', s.next_due ? s.next_due.reference : 'aucune échéance fixée')}
            </div>

            ${pend.length ? `
            <section class="bg-white rounded-2xl border border-amber-200 shadow-sm overflow-hidden">
                <div class="bg-amber-50 px-6 py-3 border-b border-amber-100">
                    <h3 class="font-black text-amber-900 text-xs uppercase tracking-widest"><i class="fas fa-user-check mr-2"></i>À approuver par le DG</h3>
                </div>
                <ul class="divide-y divide-gray-100">${pend.map(pendingRow).join('')}</ul>
            </section>` : ''}

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse min-w-[900px]">
                        <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-400 font-black tracking-widest">
                            <tr>
                                <th class="py-3 px-4">Réf.</th>
                                <th class="py-3 px-4">Prêteur / emprunteur</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4 text-right">Montant</th>
                                <th class="py-3 px-4">Conditions</th>
                                <th class="py-3 px-4">Échéance</th>
                                <th class="py-3 px-4 text-right">Reste dû</th>
                                <th class="py-3 px-4">Statut</th>
                                <th class="py-3 px-4 text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            ${D.loans.length ? D.loans.map(loanRows).join('') : `<tr><td colspan="9" class="py-10 text-center text-gray-400 font-bold">Aucun financement enregistré.${D.can.record ? ' « Nouveau financement » enregistre un emprunt ou un prêt.' : ''}</td></tr>`}
                        </tbody>
                    </table>
                </div>
            </section>`;

        $root.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => act(b.dataset)));
    }

    function card(label, value, icon, sub) {
        return `<div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest flex items-center gap-2"><i class="fas ${icon}"></i>${esc(label)}</p>
            <p class="text-2xl font-black text-gray-900 mt-2">${esc(value)}</p>
            <p class="text-[10px] font-bold text-gray-400 mt-1">${esc(sub)}</p>
        </div>`;
    }

    function pendingRow(p) {
        const l = p.l;
        const what = p.type === 'loan'
            ? `<b>${esc(l.reference)}</b> · ${l.direction === 'borrow' ? 'emprunt ' + (de(l.counterparty).startsWith("d'") ? "d'" : 'de ') : 'prêt à '}<b>${esc(l.counterparty)}</b> · ${F(l.amount)} F le ${esc(l.date_fr)} · ${esc(terms(l))}${l.funds_source === 'opening' ? ' · fonds déjà sur ' + esc(l.treasury_name) + ' (solde d\'ouverture)' : ' · via ' + esc(l.treasury_name)}`
            : `<b>${esc(l.reference)}</b> · remboursement ${l.direction === 'borrow' ? 'à' : 'de'} <b>${esc(l.counterparty)}</b> · ${F(p.m.amount)} F le ${esc(p.m.date_fr)} · via ${esc(p.m.treasury_name)}`;
        const by = p.type === 'loan' ? l.created_by_name : p.m.created_by_name;
        return `<li class="px-6 py-3 flex flex-wrap items-center gap-3 justify-between">
            <span class="text-sm text-gray-700">${what}<span class="block text-[10px] text-gray-400 font-bold">Saisi par ${esc(by || '—')}</span></span>
            ${D.can.approve
                ? `<button type="button" data-act="decide" data-type="${p.type}" data-id="${p.type === 'loan' ? l.id : p.m.id}" data-loan="${l.id}"
                        class="bg-gray-900 hover:bg-black text-white px-4 py-2 rounded-lg font-bold text-xs">Examiner</button>`
                : '<span class="text-[10px] font-black uppercase tracking-widest text-amber-700">Attend le DG</span>'}
        </li>`;
    }

    function loanRows(l) {
        const b = l.balances;
        const open = OPEN.has(+l.id);
        const kind = D.kinds[l.counterparty_kind] || '';
        let html = `<tr class="hover:bg-gray-50">
            <td class="py-3 px-4 whitespace-nowrap"><span class="font-mono text-xs font-black">${esc(l.reference)}</span>
                <span class="block text-[10px] font-black uppercase tracking-wider ${l.direction === 'borrow' ? 'text-rose-600' : 'text-emerald-700'}">${l.direction === 'borrow' ? 'LPC emprunte' : 'LPC prête'}</span></td>
            <td class="py-3 px-4 min-w-[220px]"><span class="font-bold text-gray-800">${esc(l.counterparty)}</span>
                <span class="block text-[10px] text-gray-400 font-bold">${esc(kind)}${l.account_code ? ' · compte ' + esc(l.account_code) : ''}</span></td>
            <td class="py-3 px-4 text-xs text-gray-500 font-bold whitespace-nowrap">${esc(l.date_fr)}</td>
            <td class="py-3 px-4 text-right font-black whitespace-nowrap">${F(l.amount)}</td>
            <td class="py-3 px-4 text-xs text-gray-600">${esc(terms(l))}</td>
            <td class="py-3 px-4 text-xs text-gray-500 font-bold whitespace-nowrap">${esc(l.due_fr || '—')}</td>
            <td class="py-3 px-4 text-right font-black whitespace-nowrap">${l.status === 'approved' ? F(b.total) : (l.status === 'closed' ? '0' : '—')}
                ${l.status === 'approved' && b.interest > 0 ? `<span class="block text-[10px] text-gray-400 font-bold">dont intérêts ${F(b.interest)}</span>` : ''}</td>
            <td class="py-3 px-4 whitespace-nowrap">${badge(l.status)}</td>
            <td class="py-3 px-4 text-right whitespace-nowrap">
                <button type="button" data-act="detail" data-id="${l.id}" aria-expanded="${open}" class="text-xs font-bold text-lpc-dark hover:underline">${open ? 'Masquer' : 'Détail'}</button>
                ${D.can.record && l.status === 'approved' ? `<button type="button" data-act="repay" data-id="${l.id}" class="ml-3 text-xs font-bold text-blue-700 hover:underline">Rembourser</button>` : ''}
            </td>
        </tr>`;
        if (open) html += `<tr class="bg-slate-50"><td colspan="9" class="px-6 py-4">${detail(l)}</td></tr>`;
        return html;
    }

    function detail(l) {
        const b = l.balances;
        const lines = [
            ['Saisi par', l.created_by_name || '—'],
            ['Décision', l.approved_by_name ? `${l.status === 'rejected' ? 'Refusé' : 'Approuvé'} par ${l.approved_by_name}${l.decision_note ? ' : ' + l.decision_note : ''}` : 'en attente du DG'],
            ['Argent', l.funds_source === 'opening' ? `déjà sur ${l.treasury_name} (solde d'ouverture, sorti du compte d'attente 471 à l'approbation)` : `via ${l.treasury_name}`],
            ['Compte du ' + (l.direction === 'borrow' ? 'prêteur' : 'débiteur'), l.account_code ? `${l.account_code} ${l.account_name}` : '—'],
        ];
        if (l.notes) lines.push(['Note', l.notes]);
        if (l.status === 'approved' || l.status === 'closed') {
            lines.push(['Reste dû', `${F(b.principal)} F de capital, ${F(b.interest)} F d'intérêts${b.pending > 0 ? ` · ${F(b.pending)} F de remboursements attendent le DG` : ''}`]);
        }
        const mv = l.movements;
        return `<dl class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1 text-xs mb-3">
                ${lines.map(([k, v]) => `<div><dt class="inline font-black text-gray-500">${esc(k)} : </dt><dd class="inline text-gray-700">${esc(v)}</dd></div>`).join('')}
            </dl>
            ${mv.length ? `<table class="w-full text-xs bg-white rounded-lg border border-gray-200">
                <thead class="text-[10px] uppercase text-gray-400 font-black"><tr>
                    <th class="py-2 px-3 text-left">Date</th><th class="py-2 px-3 text-left">Mouvement</th>
                    <th class="py-2 px-3 text-right">Montant</th><th class="py-2 px-3 text-left">Détail</th><th class="py-2 px-3 text-left">Statut</th></tr></thead>
                <tbody class="divide-y divide-gray-100">${mv.map(m => `<tr>
                    <td class="py-2 px-3">${esc(m.date_fr)}</td>
                    <td class="py-2 px-3 font-bold">${m.kind === 'interest' ? `Intérêts, mois ${esc(m.period_no)}` : 'Remboursement'}</td>
                    <td class="py-2 px-3 text-right font-black">${F(m.amount)}</td>
                    <td class="py-2 px-3 text-gray-500">${m.kind === 'repayment' ? (m.status === 'approved' ? `intérêts ${F(m.interest_part)} · capital ${F(m.principal_part)} · ${esc(m.treasury_name)}` : esc(m.treasury_name || '')) + (m.decision_note ? ' · ' + esc(m.decision_note) : '') : 'selon les conditions approuvées'}</td>
                    <td class="py-2 px-3">${m.status === 'approved' ? '<span class="text-emerald-700 font-bold">Comptabilisé</span>' : m.status === 'pending' ? '<span class="text-amber-700 font-bold">En attente du DG</span>' : '<span class="text-rose-700 font-bold">Refusé</span>'}</td>
                </tr>`).join('')}</tbody></table>` : '<p class="text-xs text-gray-400 font-bold">Aucun intérêt ni remboursement pour l\'instant.</p>'}`;
    }

    function act(ds) {
        const id = +ds.id;
        if (ds.act === 'detail') { OPEN.has(id) ? OPEN.delete(id) : OPEN.add(id); render(); return; }
        if (ds.act === 'new') return newLoan();
        if (ds.act === 'repay') return repay(D.loans.find(l => +l.id === id));
        if (ds.act === 'decide') return decide(ds.type, id, D.loans.find(l => +l.id === +ds.loan));
    }

    // ------------------------------------------------------------------ the window
    const $modal = document.getElementById('modal-loan');
    const $title = document.getElementById('loan-modal-title');
    const $body = document.getElementById('loan-modal-body');
    const $actions = document.getElementById('loan-modal-actions');
    let lastFocus = null;
    function openModal(title, body, buttons) {
        lastFocus = document.activeElement;
        $title.textContent = title;
        $body.innerHTML = body;
        $actions.innerHTML = '<button type="button" data-loan-close class="px-5 py-2.5 text-sm font-bold text-gray-500">Annuler</button>'
            + buttons.map((b, i) => `<button type="button" data-btn="${i}" class="px-6 py-2.5 rounded-lg font-bold text-sm shadow-md text-white ${b.cls || 'bg-gray-900'}">${esc(b.label)}</button>`).join('');
        $actions.querySelectorAll('[data-btn]').forEach(el => el.addEventListener('click', async () => {
            el.disabled = true;
            try { await buttons[+el.dataset.btn].run(); } finally { el.disabled = false; }
        }));
        $modal.querySelectorAll('[data-loan-close]').forEach(el => el.onclick = closeModal);
        $modal.classList.remove('hidden');
        $body.querySelector('input, select, textarea')?.focus();
    }
    function closeModal() { $modal.classList.add('hidden'); lastFocus?.focus?.(); }
    $modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
    const val = (id) => document.getElementById(id)?.value ?? '';
    const field = (id, label, input, hint = '') => `<div><label for="${id}" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">${label}</label>${input}${hint ? `<p class="text-[10px] font-bold text-gray-500 mt-1">${hint}</p>` : ''}</div>`;
    const IN = 'w-full bg-white border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-treasury-light';
    const treasuryOptions = (sel) => D.treasury.map(t => `<option value="${t.id}"${+t.id === +sel ? ' selected' : ''}>${esc(t.name)} (${F(t.balance)} F${t.coa_code ? ' · ' + esc(t.coa_code) : ''})</option>`).join('');

    function newLoan() {
        const cps = D.counterparties.map(c => `<option value="${c.id}">${esc(c.name)} · ${esc(D.kinds[c.kind] || c.kind)}</option>`).join('');
        const kinds = Object.entries(D.kinds).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
        const openings = D.openings.map(o => `<option value="${o.id}" data-amount="${o.amount}" data-date="${o.date}">${esc(o.treasury_name)} · ${F(o.amount)} F du ${esc(o.date_fr)}</option>`).join('');
        openModal('Nouveau financement', `
            <fieldset><legend class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Sens</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex items-center gap-2 bg-white border border-gray-200 rounded-lg p-2.5 text-sm font-bold cursor-pointer"><input type="radio" name="ln_dir" value="borrow" checked> LPC emprunte</label>
                    <label class="flex items-center gap-2 bg-white border border-gray-200 rounded-lg p-2.5 text-sm font-bold cursor-pointer"><input type="radio" name="ln_dir" value="lend"> LPC prête</label>
                </div>
            </fieldset>
            ${field('ln_cp', 'Prêteur ou emprunteur *', `<select id="ln_cp" class="${IN}">${cps}<option value="new">+ Nouveau prêteur / emprunteur…</option></select>`)}
            <div id="ln_new_cp" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-3 bg-white border border-gray-200 rounded-xl p-3">
                ${field('ln_cp_name', 'Nom *', `<input type="text" id="ln_cp_name" maxlength="150" class="${IN}">`)}
                ${field('ln_cp_kind', 'Nature *', `<select id="ln_cp_kind" class="${IN}">${kinds}</select>`, 'Fixe son compte : dirigeant 4621, société liée 466, banque 162, tontine 168.')}
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                ${field('ln_date', 'Date *', `<input type="date" id="ln_date" max="${today()}" value="${today()}" class="${IN}">`)}
                ${field('ln_amount', 'Montant (FCFA) *', `<input type="number" id="ln_amount" min="1" step="1" class="${IN} text-right">`)}
            </div>
            <fieldset><legend class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">L'argent</legend>
                <label class="flex items-center gap-2 text-sm font-bold mb-2"><input type="radio" name="ln_src" value="treasury" checked> Passe par un compte de trésorerie</label>
                <select id="ln_treasury" class="${IN} mb-2" aria-label="Compte de trésorerie">${treasuryOptions()}</select>
                ${openings ? `<label class="flex items-center gap-2 text-sm font-bold mb-2" id="ln_src_open_lbl"><input type="radio" name="ln_src" value="opening"> Est déjà sur le compte : son solde d'ouverture, en attente (471)</label>
                <select id="ln_opening" class="${IN} hidden" aria-label="Solde d'ouverture">${openings}</select>` : ''}
            </fieldset>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                ${field('ln_kind', 'Intérêts', `<select id="ln_kind" class="${IN}">${Object.entries(D.interest).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select>`)}
                <div id="ln_rate_box" class="hidden">${field('ln_rate', 'Taux mensuel (%)', `<input type="number" id="ln_rate" min="0" max="100" step="0.01" class="${IN} text-right">`)}</div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                ${field('ln_fee', 'Frais unique (FCFA)', `<input type="number" id="ln_fee" min="0" step="1" value="0" class="${IN} text-right">`, 'Retenu sur les fonds versés.')}
                ${field('ln_due', 'Échéance', `<input type="date" id="ln_due" class="${IN}">`, 'Le DG et le CF sont prévenus avant.')}
            </div>
            ${field('ln_notes', 'Note', `<textarea id="ln_notes" rows="2" maxlength="500" class="${IN} font-medium"></textarea>`)}
            <p class="text-xs font-bold text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">Le DG l'approuve avant que rien ne soit comptabilisé ni ne bouge en trésorerie.</p>
        `, [{ label: 'Enregistrer pour approbation', cls: 'bg-treasury-dark hover:bg-green-800', run: submitLoan }]);

        const sync = () => {
            const dir = document.querySelector('input[name=ln_dir]:checked').value;
            document.getElementById('ln_new_cp').classList.toggle('hidden', val('ln_cp') !== 'new' && D.counterparties.length > 0);
            if (!D.counterparties.length) document.getElementById('ln_cp').value = 'new';
            const lbl = document.getElementById('ln_src_open_lbl');
            if (lbl) {
                lbl.classList.toggle('hidden', dir !== 'borrow');
                if (dir !== 'borrow') document.querySelector('input[name=ln_src][value=treasury]').checked = true;
            }
            const src = document.querySelector('input[name=ln_src]:checked').value;
            document.getElementById('ln_treasury').classList.toggle('hidden', src !== 'treasury');
            const op = document.getElementById('ln_opening');
            if (op) {
                op.classList.toggle('hidden', src !== 'opening');
                if (src === 'opening') {
                    const o = op.selectedOptions[0];
                    document.getElementById('ln_amount').value = o.dataset.amount;
                    document.getElementById('ln_date').value = o.dataset.date;
                    document.getElementById('ln_fee').value = 0;
                }
            }
            document.getElementById('ln_rate_box').classList.toggle('hidden', val('ln_kind') === 'none');
        };
        $body.querySelectorAll('input, select').forEach(el => el.addEventListener('change', sync));
        sync();
    }

    async function submitLoan() {
        let cp = val('ln_cp');
        if (cp === 'new' || !D.counterparties.length) {
            const r = await post({ action: 'add_counterparty', name: val('ln_cp_name'), kind: val('ln_cp_kind') });
            if (!r) return;
            cp = r.data.id;
            D.counterparties.push({ id: cp, name: val('ln_cp_name'), kind: val('ln_cp_kind') });
            const sel = document.getElementById('ln_cp');
            sel.insertAdjacentHTML('afterbegin', `<option value="${cp}">${esc(val('ln_cp_name'))}</option>`);
            sel.value = cp;
        }
        const src = document.querySelector('input[name=ln_src]:checked').value;
        const r = await post({
            action: 'record_loan',
            direction: document.querySelector('input[name=ln_dir]:checked').value,
            counterparty_id: +cp,
            loan_date: val('ln_date'),
            amount: +val('ln_amount'),
            funds_source: src,
            treasury_account_id: src === 'treasury' ? +val('ln_treasury') : null,
            opening_entry_id: src === 'opening' ? +val('ln_opening') : null,
            interest_kind: val('ln_kind'),
            monthly_rate: +val('ln_rate') || 0,
            fee_amount: +val('ln_fee') || 0,
            due_date: val('ln_due'),
            notes: val('ln_notes'),
        });
        if (!r) return;
        closeModal();
        alertBox(r.message);
        load();
    }

    function repay(l) {
        if (!l) return;
        const b = l.balances;
        const left = b.total - b.pending;
        openModal(`Remboursement · ${l.reference}`, `
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-sm">
                <p class="font-black text-gray-800">${l.direction === 'borrow' ? 'LPC rembourse' : 'LPC est remboursé par'} ${esc(l.counterparty)}</p>
                <p class="text-xs text-gray-600 mt-1">Reste dû : <b>${F(b.total)} F</b> (capital ${F(b.principal)} F, intérêts ${F(b.interest)} F)${b.pending > 0 ? `, dont ${F(b.pending)} F déjà en attente du DG` : ''}.</p>
                <p class="text-[10px] text-gray-500 font-bold mt-1">Un remboursement paie d'abord les intérêts, puis le capital.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                ${field('rp_amount', 'Montant (FCFA) *', `<input type="number" id="rp_amount" min="1" max="${Math.round(left)}" step="1" class="${IN} text-right">`, `${F(left)} F au plus.`)}
                ${field('rp_date', 'Date *', `<input type="date" id="rp_date" min="${esc(l.loan_date)}" max="${today()}" value="${today()}" class="${IN}">`)}
            </div>
            ${field('rp_treasury', l.direction === 'borrow' ? 'Payé depuis *' : 'Reçu sur *', `<select id="rp_treasury" class="${IN}">${treasuryOptions(l.treasury_account_id)}</select>`)}
            ${field('rp_notes', 'Note', `<textarea id="rp_notes" rows="2" maxlength="500" class="${IN} font-medium"></textarea>`)}
            <p class="text-xs font-bold text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">Le DG l'approuve avant que rien ne soit comptabilisé ni ne bouge en trésorerie.</p>
        `, [{ label: 'Enregistrer pour approbation', cls: 'bg-treasury-dark hover:bg-green-800', run: async () => {
            const r = await post({ action: 'record_repayment', loan_id: +l.id, amount: +val('rp_amount'), date: val('rp_date'),
                                   treasury_account_id: +val('rp_treasury'), notes: val('rp_notes') });
            if (!r) return;
            closeModal(); alertBox(r.message); load();
        } }]);
    }

    function decide(type, id, l) {
        if (!l) return;
        const m = type === 'repayment' ? l.movements.find(x => +x.id === id) : null;
        const isLoan = type === 'loan';
        let effect;
        if (isLoan) {
            effect = l.funds_source === 'opening'
                ? `Les ${F(l.amount)} F sont déjà sur ${esc(l.treasury_name)} (son solde d'ouverture). L'approbation les sort du compte d'attente 471 pour le compte de ${esc(l.counterparty)}${l.account_code ? ' (' + esc(l.account_code) + ')' : ''} : aucun argent ne bouge.`
                : l.direction === 'borrow'
                    ? `${F(l.amount - l.fee_amount)} F entrent sur ${esc(l.treasury_name)}${+l.fee_amount > 0 ? ` (${F(l.fee_amount)} F de frais retenus, en charge 631)` : ''} ; LPC doit ${F(l.amount)} F à ${esc(l.counterparty)}.`
                    : `${F(l.amount - l.fee_amount)} F sortent de ${esc(l.treasury_name)}${+l.fee_amount > 0 ? ` (${F(l.fee_amount)} F de frais retenus, en produit 771)` : ''} ; ${esc(l.counterparty)} doit ${F(l.amount)} F à LPC.`;
        } else {
            effect = `${F(m.amount)} F ${l.direction === 'borrow' ? 'sortent de' : 'entrent sur'} ${esc(m.treasury_name)}, d'abord sur les intérêts (${F(Math.min(m.amount, l.balances.interest))} F), puis sur le capital.`;
        }
        openModal(isLoan ? `Décision · ${l.reference}` : `Décision · remboursement ${l.reference}`, `
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-sm space-y-1">
                <p class="font-black text-gray-800">${isLoan ? (l.direction === 'borrow' ? 'Emprunt ' + (de(l.counterparty).startsWith("d'") ? "d'" : 'de ') : 'Prêt à ') : 'Remboursement ' + (l.direction === 'borrow' ? 'à ' : 'de ')}${esc(l.counterparty)}</p>
                <p class="text-xs text-gray-600">${isLoan ? `${F(l.amount)} F le ${esc(l.date_fr)} · ${esc(terms(l))}${l.due_fr ? ' · échéance ' + esc(l.due_fr) : ''}` : `${F(m.amount)} F le ${esc(m.date_fr)}`}</p>
                ${(isLoan ? l.notes : m.notes) ? `<p class="text-xs text-gray-500 italic">${esc(isLoan ? l.notes : m.notes)}</p>` : ''}
            </div>
            <p class="text-xs font-bold text-gray-700 bg-emerald-50 border border-emerald-200 rounded-lg p-3">Si vous approuvez : ${effect}</p>
            ${field('dc_note', 'Note (obligatoire pour refuser)', `<textarea id="dc_note" rows="2" maxlength="500" class="${IN} font-medium"></textarea>`)}
        `, [
            { label: 'Refuser', cls: 'bg-rose-600 hover:bg-rose-700', run: () => send('reject') },
            { label: 'Approuver et comptabiliser', cls: 'bg-treasury-dark hover:bg-green-800', run: () => send('approve') },
        ]);
        async function send(decision) {
            const r = await post({ action: isLoan ? 'decide_loan' : 'decide_repayment', id, decision, note: val('dc_note') });
            if (!r) return;
            closeModal(); alertBox(r.message); load();
        }
    }

    window.LpcLoans = { load };
})();
