/**
 * assets/js/modules/accounting-opening_balance.js
 * -----------------------------------------------------------------------------
 * Bilan d'Ouverture — one screen for the first-year opening balances.
 *
 * Data model:
 *   Every active CoA row is a candidate line. Each carries `debit` and
 *   `credit` amounts (both default 0). Save posts one balanced OD journal
 *   entry dated Jan 1 of the chosen year, pinned to
 *   financial_years.opening_balance_journal_id.
 *
 * Editability:
 *   The screen refuses to save once the year has any other journal entry.
 *   The banner and the Save button both reflect that lock. Server enforces
 *   the same rule; the UI just matches so users don't waste keystrokes.
 *
 * Treasury rows are highlighted and pre-populated from the stored
 * treasury_accounts.balance the first time the screen is opened for a
 * fresh year, so the operator sees the numbers already in the system
 * instead of having to re-key them. Saving the opening JE also writes
 * the mirror value back to treasury_accounts.balance.
 *
 * Per-tier subledger (per-client AR, per-supplier AP) is NOT here — that
 * flows through opening invoices in the invoice screen. The banner points
 * to it explicitly.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    const API   = '/api/v1/opening_balance_controller.php';
    const $root = document.getElementById('ob-root');

    let STATE = {
        year:       (new Date()).getFullYear(),
        accounts:   [],   // [{id, code, name, class, treasury_*, debit, credit}]
        existing:   null,
        editable:   true,
        blocking_entries: 0,
        can_save:   false,
        dirty:      false,
    };

    const CLASS_LABEL = {
        '1': 'Classe 1 — Capitaux',
        '2': 'Classe 2 — Immobilisations',
        '3': 'Classe 3 — Stocks',
        '4': 'Classe 4 — Tiers',
        '5': 'Classe 5 — Trésorerie',
        '6': 'Classe 6 — Charges',
        '7': 'Classe 7 — Produits',
        '8': 'Classe 8 — Autres charges / produits',
        '9': 'Classe 9 — Comptabilité analytique',
    };

    // Classes 6/7/8 are P&L. Their opening balance should almost always be
    // zero on a fresh handover (the compte de résultat resets each year).
    // We keep them visible but tinted, so operators aren't confused by their
    // absence.
    const PNL_CLASSES = new Set(['6', '7', '8']);

    // ---------- utils ----------------------------------------------------
    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = n => Math.round(Number(n) || 0).toLocaleString('fr-FR');

    function toast(msg, type = 'success') {
        if (window.LPC?.toast) return LPC.toast(type, msg);
        alert(msg);
    }

    async function apiGet(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params });
        const r  = await fetch(`${API}?${qs}`);
        const j  = await r.json();
        if (j.status !== 'success') throw new Error(j.message || 'Erreur.');
        return j.data;
    }

    async function apiPost(payload) {
        const r = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const j = await r.json();
        if (j.status !== 'success') throw new Error(j.message || 'Erreur.');
        return j.data;
    }

    // ---------- load -----------------------------------------------------
    async function load(year) {
        const d = await apiGet('load', { year });
        STATE.year       = d.year;
        STATE.accounts   = d.accounts || [];
        STATE.existing   = d.existing;
        STATE.editable   = !!d.editable;
        STATE.blocking_entries = d.blocking_entries || 0;
        STATE.can_save   = !!d.can_save;
        STATE.dirty      = false;

        // Pre-populate treasury lines from the stored balance IF there is no
        // existing opening entry yet (i.e. never saved). Once an opening JE
        // exists, its lines are the source of truth.
        if (!STATE.existing) {
            STATE.accounts.forEach(a => {
                if (a.treasury_balance !== null && a.debit === 0 && a.credit === 0) {
                    const b = Number(a.treasury_balance);
                    if (b > 0) a.debit  = b;
                    else if (b < 0) a.credit = -b;
                }
            });
        }
        render();
    }

    // ---------- render ---------------------------------------------------
    function render() {
        const totals = computeTotals();
        const diff   = totals.debit - totals.credit;
        const balanced = Math.round(diff) === 0;

        $root.innerHTML = `
            ${renderHeader(totals, balanced, diff)}
            ${renderLockBanner()}
            ${renderTierNote()}
            ${renderTable()}
        `;

        // Header wiring
        document.getElementById('ob-year').addEventListener('change', e => {
            const y = parseInt(e.target.value, 10);
            if (!Number.isFinite(y)) return;
            if (STATE.dirty && !confirm('Modifications non enregistrées. Changer d’exercice et perdre les changements ?')) {
                e.target.value = STATE.year;
                return;
            }
            load(y).catch(err => toast(err.message, 'error'));
        });
        document.getElementById('ob-save')?.addEventListener('click', save);
        document.getElementById('ob-reset')?.addEventListener('click', () => load(STATE.year));

        // Filter
        document.getElementById('ob-filter').addEventListener('input', renderTableOnly);
        document.getElementById('ob-show-empty').addEventListener('change', renderTableOnly);
        document.getElementById('ob-show-pnl').addEventListener('change', renderTableOnly);

        wireAmountInputs();
    }

    function renderHeader(totals, balanced, diff) {
        const currentYear = (new Date()).getFullYear();
        const years = [];
        for (let y = currentYear + 1; y >= currentYear - 5; y--) years.push(y);

        const savedTag = STATE.existing
            ? `<span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded ml-2">
                 ${esc(STATE.existing.reference)} · ${esc(STATE.existing.status)}
               </span>`
            : `<span class="text-xs font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded ml-2">Aucune ouverture enregistrée</span>`;

        return `
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm px-6 py-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="font-black text-lpc-dark tracking-wide text-lg">
                            Exercice
                            <select id="ob-year" class="ml-2 px-3 py-1.5 border border-gray-200 rounded-lg font-mono">
                                ${years.map(y =>
                                    `<option value="${y}"${y === STATE.year ? ' selected' : ''}>${y}</option>`
                                ).join('')}
                            </select>
                            ${savedTag}
                        </h2>
                        <p class="text-xs text-gray-500 mt-1">
                            Saisissez le solde d’ouverture de chaque compte. La somme des débits doit égaler la somme des crédits.
                        </p>
                    </div>
                    <div class="flex items-center gap-6">
                        <div class="text-right">
                            <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Σ Débit</div>
                            <div class="text-lg font-black font-mono text-gray-800">${fmt(totals.debit)}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Σ Crédit</div>
                            <div class="text-lg font-black font-mono text-gray-800">${fmt(totals.credit)}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Écart</div>
                            <div class="text-lg font-black font-mono ${balanced ? 'text-emerald-700' : 'text-rose-600'}">
                                ${balanced ? '0' : (diff > 0 ? '+' : '') + fmt(diff)}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="ob-reset"
                                class="px-4 py-2 text-sm font-bold text-gray-600 hover:text-gray-800">
                                Réinitialiser
                            </button>
                            <button type="button" id="ob-save"
                                ${(!STATE.editable || !STATE.can_save || !balanced) ? 'disabled' : ''}
                                class="px-6 py-2 rounded-xl font-bold text-sm shadow-md
                                       ${(!STATE.editable || !STATE.can_save || !balanced)
                                          ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                                          : 'bg-lpc-dark hover:bg-green-800 text-white'}">
                                Enregistrer l’ouverture
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        `;
    }

    function renderLockBanner() {
        if (STATE.editable) return '';
        return `
            <div class="bg-amber-50 border border-amber-200 text-amber-900 px-5 py-3 rounded-xl text-sm">
                <b>Bilan d’ouverture figé.</b>
                L’exercice ${STATE.year} contient déjà ${STATE.blocking_entries} écriture(s) autres que l’ouverture ;
                la saisie est en lecture seule. Toute correction doit passer par une écriture OD manuelle (Journal → Nouvelle écriture).
            </div>
        `;
    }

    function renderTierNote() {
        return `
            <div class="bg-sky-50 border border-sky-200 text-sky-900 px-5 py-3 rounded-xl text-xs leading-relaxed">
                <b>Détail par tiers ?</b>
                Ce tableau porte le total agrégé de chaque compte (par exemple <span class="font-mono">411 Clients</span> global).
                Pour poser un solde ouvert <em>par client</em> ou <em>par fournisseur</em>, créez une <b>facture d’ouverture</b>
                (Comptabilité → Factures / Achats → Nouvelle facture, datée du 1<sup>er</sup> janvier) pour chaque tiers.
                La somme de ces factures doit égaler la ligne 411/401 saisie ici.
            </div>
        `;
    }

    function renderTable() {
        return `
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <header class="px-6 py-3 border-b border-gray-100 flex items-center gap-4 flex-wrap">
                    <input type="text" id="ob-filter" placeholder="Rechercher code ou nom..."
                           class="flex-1 min-w-[16rem] max-w-md px-3 py-1.5 border border-gray-200 rounded-lg text-sm">
                    <label class="text-xs text-gray-600 font-bold flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="ob-show-empty" checked> Afficher les comptes vides
                    </label>
                    <label class="text-xs text-gray-600 font-bold flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="ob-show-pnl"> Afficher classes 6/7/8 (P&amp;L)
                    </label>
                </header>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 sticky top-0">
                            <tr>
                                <th class="text-left px-4 py-2 font-bold w-28">Code</th>
                                <th class="text-left px-4 py-2 font-bold">Compte</th>
                                <th class="text-left px-4 py-2 font-bold w-40">Parent OHADA</th>
                                <th class="text-right px-4 py-2 font-bold w-40">Débit (FCFA)</th>
                                <th class="text-right px-4 py-2 font-bold w-40">Crédit (FCFA)</th>
                            </tr>
                        </thead>
                        <tbody id="ob-body" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </section>
        `;
    }

    function renderTableOnly() {
        const body = document.getElementById('ob-body');
        const q    = document.getElementById('ob-filter').value.trim().toLowerCase();
        const showEmpty = document.getElementById('ob-show-empty').checked;
        const showPnl   = document.getElementById('ob-show-pnl').checked;
        const readonly  = !STATE.editable || !STATE.can_save;

        let lastClass = null;
        const parts = [];

        STATE.accounts
            .filter(a => {
                if (!showPnl && PNL_CLASSES.has(a.class)) return false;
                if (!showEmpty && a.debit === 0 && a.credit === 0) return false;
                if (q) {
                    const hay = (a.code + ' ' + a.name + ' ' + a.ohada_number + ' ' + a.ohada_name).toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            })
            .forEach(a => {
                if (a.class !== lastClass) {
                    lastClass = a.class;
                    parts.push(`
                        <tr class="bg-slate-50">
                            <td colspan="5" class="px-4 py-1.5 text-[10px] uppercase tracking-widest font-black text-slate-500">
                                ${esc(CLASS_LABEL[a.class] || 'Classe ' + a.class)}
                            </td>
                        </tr>
                    `);
                }
                const treasuryTag = a.treasury_id
                    ? `<span class="ml-2 text-[10px] font-bold text-sky-700 bg-sky-50 px-1.5 py-0.5 rounded">TRÉSORERIE · ${esc(a.treasury_name || '')}</span>`
                    : '';
                const attrs = readonly ? 'readonly disabled' : '';
                const tint  = PNL_CLASSES.has(a.class) ? 'bg-gray-50/40' : '';
                parts.push(`
                    <tr class="${tint}">
                        <td class="px-4 py-2 font-mono font-bold">${esc(a.code)}</td>
                        <td class="px-4 py-2">${esc(a.name)}${treasuryTag}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">
                            <span class="font-mono">${esc(a.ohada_number)}</span> ${esc(a.ohada_name)}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" min="0" step="1" ${attrs}
                                   data-account-id="${a.id}" data-side="debit"
                                   value="${a.debit || ''}"
                                   class="w-full px-2 py-1 border border-gray-200 rounded text-right font-mono
                                          ${readonly ? 'bg-gray-100' : ''}">
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input type="number" min="0" step="1" ${attrs}
                                   data-account-id="${a.id}" data-side="credit"
                                   value="${a.credit || ''}"
                                   class="w-full px-2 py-1 border border-gray-200 rounded text-right font-mono
                                          ${readonly ? 'bg-gray-100' : ''}">
                        </td>
                    </tr>
                `);
            });

        if (!parts.length) {
            parts.push(`<tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 text-sm">
                Aucun compte à afficher — élargissez le filtre ou cochez les cases ci-dessus.
            </td></tr>`);
        }

        body.innerHTML = parts.join('');
        wireAmountInputs();
    }

    function wireAmountInputs() {
        document.querySelectorAll('#ob-body input[data-account-id]').forEach(input => {
            input.addEventListener('input', () => {
                const id   = +input.dataset.accountId;
                const side = input.dataset.side;
                const v    = Math.max(0, Number(input.value) || 0);
                const a    = STATE.accounts.find(x => x.id === id);
                if (!a) return;
                a[side] = v;
                // Enforce mutual exclusion: entering a debit clears any credit
                // on the same line, and vice-versa — a single account can't
                // sit on both sides of an opening balance.
                if (v > 0 && side === 'debit'  && a.credit > 0) {
                    a.credit = 0;
                    const other = document.querySelector(`#ob-body input[data-account-id="${id}"][data-side="credit"]`);
                    if (other) other.value = '';
                }
                if (v > 0 && side === 'credit' && a.debit  > 0) {
                    a.debit = 0;
                    const other = document.querySelector(`#ob-body input[data-account-id="${id}"][data-side="debit"]`);
                    if (other) other.value = '';
                }
                STATE.dirty = true;
                refreshHeader();
            });
        });
    }

    function refreshHeader() {
        // Cheap partial re-render of the totals + Save button state.
        const totals   = computeTotals();
        const diff     = totals.debit - totals.credit;
        const balanced = Math.round(diff) === 0;
        // Replace the header section only.
        const oldHeader = $root.querySelector('section');
        if (!oldHeader) return;
        const wrap = document.createElement('div');
        wrap.innerHTML = renderHeader(totals, balanced, diff);
        oldHeader.replaceWith(wrap.firstElementChild);
        // Re-wire the year+save controls after replace.
        document.getElementById('ob-year').addEventListener('change', e => {
            const y = parseInt(e.target.value, 10);
            if (STATE.dirty && !confirm('Modifications non enregistrées. Changer d’exercice ?')) {
                e.target.value = STATE.year;
                return;
            }
            load(y).catch(err => toast(err.message, 'error'));
        });
        document.getElementById('ob-save')?.addEventListener('click', save);
        document.getElementById('ob-reset')?.addEventListener('click', () => load(STATE.year));
    }

    function computeTotals() {
        let d = 0, c = 0;
        STATE.accounts.forEach(a => { d += Number(a.debit) || 0; c += Number(a.credit) || 0; });
        return { debit: d, credit: c };
    }

    async function save() {
        const totals = computeTotals();
        if (Math.round(totals.debit - totals.credit) !== 0) {
            toast('Le bilan n’est pas équilibré.', 'error');
            return;
        }
        const lines = STATE.accounts
            .filter(a => a.debit > 0 || a.credit > 0)
            .map(a => ({ account_id: a.id, debit: a.debit, credit: a.credit }));
        if (!lines.length) { toast('Aucune ligne à enregistrer.', 'error'); return; }

        const btn = document.getElementById('ob-save');
        btn.disabled = true;
        try {
            const d = await apiPost({ action: 'save', year: STATE.year, lines });
            toast(`Enregistré — ${d.reference} · Σ ${fmt(d.total_debit)}`);
            await load(STATE.year);
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            btn.disabled = false;
        }
    }

    // ---------- boot -----------------------------------------------------
    load(STATE.year).catch(e => {
        $root.innerHTML = `<div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl">${esc(e.message)}</div>`;
    });
})();
