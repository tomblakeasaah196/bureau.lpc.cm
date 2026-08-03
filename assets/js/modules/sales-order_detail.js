/**
 * assets/js/modules/sales-order_detail.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — modules/sales/order_detail.php.
 *
 * The sell-side mirror of inventory-po_detail.js. Before this page existed, a
 * sales order's list row was the entire truth available about it: answering
 * "what actually happened to CMD-2604-90B6?" meant opening Dispatch, then
 * Facturation, then the client's file, and reconciling three screens by eye.
 *
 * One rule carried through from migration 062: the "Remise" column shows only
 * what an operator DECLARED as a reduction. A line whose price moved because
 * the client was repriced shows no remise at all — that belongs to the
 * "Changements de prix" section, which is a different thing and is presented as
 * one. Nothing on this page subtracts two prices and calls the result a
 * discount.
 *
 * Migration 070 · the same rule, mirrored. A "Majoration" column carries only
 * what an operator DECLARED as a one-off markup. A line whose price moved to a
 * higher tariff for the client shows no majoration either — again, that is a
 * price change, still in the "Changements de prix" section. Nothing subtracts
 * two prices and calls the result a surcharge either.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    const SO_ID = Number(window.LPC_SO_ID || 0);

    const STATUS_BADGES = {
        pending:    '<span class="px-3 py-1 bg-amber-100 text-amber-800 text-[10px] font-black uppercase rounded-lg">En attente</span>',
        dispatched: '<span class="px-3 py-1 bg-blue-100 text-blue-800 text-[10px] font-black uppercase rounded-lg"><i class="fas fa-truck mr-1"></i>En transit</span>',
        delivered:  '<span class="px-3 py-1 bg-emerald-100 text-emerald-800 text-[10px] font-black uppercase rounded-lg"><i class="fas fa-check-double mr-1"></i>Livré</span>',
        cancelled:  '<span class="px-3 py-1 bg-red-100 text-red-800 text-[10px] font-black uppercase rounded-lg"><i class="fas fa-ban mr-1"></i>Annulé</span>',
        partial_dispatch: '<span class="px-3 py-1 bg-orange-100 text-orange-800 text-[10px] font-black uppercase rounded-lg">Partiel</span>',
    };

    const FULFILMENT_BADGES = {
        pending:   '<span class="px-3 py-1 bg-gray-100 text-gray-700 text-[10px] font-black uppercase rounded-lg">Rien d\'expédié</span>',
        partial:   '<span class="px-3 py-1 bg-amber-100 text-amber-800 text-[10px] font-black uppercase rounded-lg">Partiellement expédié</span>',
        complete:  '<span class="px-3 py-1 bg-emerald-100 text-emerald-800 text-[10px] font-black uppercase rounded-lg">Intégralement expédié</span>',
        cancelled: '<span class="px-3 py-1 bg-red-100 text-red-800 text-[10px] font-black uppercase rounded-lg">Annulée</span>',
    };

    const DELIVERY_MODES = {
        own_fleet:     { icon: 'fa-truck',    short: 'Flotte LPC' },
        supplier:      { icon: 'fa-industry', short: 'Fournisseur' },
        client_pickup: { icon: 'fa-store',    short: 'Enlèvement' },
    };

    document.addEventListener('DOMContentLoaded', load);

    async function load() {
        if (!SO_ID) return fail('Commande non spécifiée.');
        try {
            const res = await fetch(`/api/v1/sales_controller.php?action=get_order_detail&id=${SO_ID}`);
            const r = await res.json();
            if (r.status !== 'success') return fail(r.message || 'Commande introuvable.');
            render(r.data);
        } catch (e) {
            fail('Impossible de charger la commande.');
        }
    }

    function fail(msg) {
        document.getElementById('so-loading').classList.add('hidden');
        const err = document.getElementById('so-error');
        err.textContent = msg;
        err.classList.remove('hidden');
    }

    function render(d) {
        const o = d.order;

        document.getElementById('so-reference').textContent = o.reference;
        document.getElementById('so-status-badge').innerHTML = STATUS_BADGES[o.status] || o.status;
        document.getElementById('so-client-name').textContent =
            o.client_name + (o.client_phone ? ' · ' + o.client_phone : '');
        document.getElementById('so-total').textContent = LPC.fmt.fcfa(o.total_amount);
        document.getElementById('so-date').textContent = LPC.fmt.date(o.date);
        document.getElementById('so-created-by').textContent = o.created_by_name || '—';

        document.getElementById('so-payment-status').innerHTML = o.payment_status === 'paid'
            ? '<span class="text-emerald-600"><i class="fas fa-check-circle"></i> Payé</span>'
            : (o.payment_status === 'partial'
                ? '<span class="text-amber-600"><i class="fas fa-adjust"></i> Partiel</span>'
                : '<span class="text-red-600"><i class="fas fa-clock"></i> Non payé</span>');

        // Remise: the declared reduction, split between lines and the order.
        const lineDisc  = Number(d.totals.line_discount) || 0;
        const orderDisc = Number(d.totals.order_discount) || 0;
        const totalDisc = Number(o.discount_amount) || 0;
        const discEl = document.getElementById('so-discount');
        if (totalDisc > 0) {
            const parts = [];
            if (lineDisc > 0)  parts.push(`${LPC.fmt.int(lineDisc)} F lignes`);
            if (orderDisc > 0) parts.push(`${LPC.fmt.int(orderDisc)} F commande`);
            discEl.innerHTML = LPC.html`${LPC.fmt.fcfa(totalDisc)}`
                + LPC.raw(`<span class="block text-[9px] font-bold text-gray-400 normal-case">${parts.join(' + ')}</span>`)
                + LPC.raw(o.discount_note
                    ? LPC.html`<span class="block text-[10px] font-medium text-gray-600 normal-case mt-0.5">${o.discount_note}</span>`
                    : '<span class="block text-[10px] italic text-red-500 normal-case mt-0.5">Sans motif</span>');
        } else {
            discEl.innerHTML = '<span class="text-gray-300">—</span>';
        }

        // Majoration (migration 070). Sibling of the remise tile above, kept
        // in its own tile rather than netted — see pricing.php. Same motif
        // field on the order (discount_note is direction-neutral in effect),
        // so the caption reuses it when a surcharge exists but no remise.
        const lineSurch = Number(d.totals.line_surcharge) || 0;
        const surchEl = document.getElementById('so-surcharge');
        if (surchEl) {
            if (lineSurch > 0) {
                surchEl.innerHTML = LPC.html`${LPC.fmt.fcfa(lineSurch)}`
                    + LPC.raw(o.discount_note
                        ? LPC.html`<span class="block text-[10px] font-medium text-gray-600 normal-case mt-0.5">${o.discount_note}</span>`
                        : '<span class="block text-[10px] italic text-red-500 normal-case mt-0.5">Sans motif</span>');
            } else {
                surchEl.innerHTML = '<span class="text-gray-300">—</span>';
            }
        }

        renderInvoiceStatus(d);
        renderCancelBanner(o);
        renderFulfilment(d);
        renderLines(d.lines);
        renderDeliveries(d.deliveries);
        renderInvoices(d.invoices);
        renderPriceChanges(d.price_changes);
        renderHeaderActions(o, d);

        document.getElementById('so-loading').classList.add('hidden');
        document.getElementById('so-content').classList.remove('hidden');
    }

    function renderInvoiceStatus(d) {
        const el = document.getElementById('so-invoice-status');
        const invs = d.invoices || [];
        const dels = d.deliveries || [];
        if (d.order.status === 'cancelled') { el.innerHTML = '<span class="text-gray-300">—</span>'; return; }
        if (dels.length === 0) { el.innerHTML = '<span class="text-gray-400 text-xs">Pas de BL</span>'; return; }
        if (invs.length === 0) {
            el.innerHTML = '<a href="/modules/accounting/invoices.php#to_invoice" class="text-amber-700 bg-amber-50 hover:bg-amber-100 px-2 py-1 rounded text-[10px] font-black uppercase">À facturer</a>';
            return;
        }
        el.innerHTML = invs.map(i =>
            LPC.html`<a href="/modules/accounting/invoices.php#invoices" class="inline-block text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-2 py-1 rounded text-[10px] font-black mr-1">${i.reference}</a>`
        ).join('');
    }

    function renderCancelBanner(o) {
        if (o.status !== 'cancelled') return;
        document.getElementById('so-cancelled-banner').classList.remove('hidden');
        document.getElementById('so-cancel-reason').textContent = o.cancellation_reason || 'Aucun motif enregistré.';
        const who = o.cancelled_by_name || 'inconnu';
        const when = o.cancelled_at ? LPC.fmt.dateTime(o.cancelled_at) : '—';
        document.getElementById('so-cancel-meta').textContent = `Par ${who} · ${when}`;
    }

    function renderFulfilment(d) {
        const t = d.totals;
        document.getElementById('fulfilment-state-badge').innerHTML =
            FULFILMENT_BADGES[d.fulfilment_state] || '';

        const pct = t.qty_ordered > 0
            ? Math.min(100, Math.round((t.qty_dispatched / t.qty_ordered) * 100)) : 0;
        const bar = document.getElementById('fulfilment-progress-bar');
        bar.style.width = pct + '%';
        if (d.fulfilment_state === 'cancelled') bar.className = 'bg-red-400 h-3 rounded-full transition-all';
        else if (pct >= 100) bar.className = 'bg-emerald-600 h-3 rounded-full transition-all';
        else if (pct > 0) bar.className = 'bg-amber-500 h-3 rounded-full transition-all';
        else bar.className = 'bg-gray-300 h-3 rounded-full transition-all';

        // "Accepté" is reported separately from "expédié" rather than folded in:
        // they diverge on a partial rejection, and that divergence is usually
        // the reason someone opened this page.
        document.getElementById('fulfilment-progress-label').textContent =
            `${t.qty_dispatched} / ${t.qty_ordered} unités expédiées (${pct} %)`
            + ` · ${t.qty_accepted} acceptées par le client`
            + (t.qty_remaining > 0 ? ` · ${t.qty_remaining} restantes` : '');
    }

    function renderLines(lines) {
        const body = document.getElementById('so-lines-body');
        body.innerHTML = '';
        if (!lines.length) {
            body.innerHTML = '<tr><td colspan="9" class="py-6 text-center text-gray-400 italic">Aucune ligne.</td></tr>';
            return;
        }
        // Remise and Majoration stay side by side rather than a single signed
        // column — the two are different quantities and are never netted (see
        // migration 070 and pricing.php). A given line will show a value in
        // at most one of them; the mutually-exclusive invariant is enforced
        // server-side.
        lines.forEach(l => {
            const disc  = Number(l.line_discount)  || 0;
            const surch = Number(l.line_surcharge) || 0;
            body.innerHTML += LPC.html`
                <tr class="hover:bg-gray-50">
                    <td class="py-3 px-6">
                        <p class="font-bold text-gray-900">${l.product_name}</p>
                        <p class="text-[10px] font-bold text-gray-400">${l.format || ''}</p>
                    </td>
                    <td class="py-3 px-4 text-center font-black text-gray-800">${l.qty_ordered}</td>
                    <td class="py-3 px-4 text-center font-black text-blue-700 bg-blue-50/40">${l.qty_dispatched}</td>
                    <td class="py-3 px-4 text-center font-black text-emerald-700 bg-emerald-50/40">${l.qty_accepted}</td>
                    <td class="py-3 px-4 text-right font-bold text-gray-500">${LPC.fmt.int(l.list_price)} F</td>
                    <td class="py-3 px-4 text-right font-black text-gray-900">${LPC.fmt.int(l.unit_price)} F</td>
                    <td class="py-3 px-4 text-right">${LPC.raw(disc > 0
                        ? `<span class="font-black text-blue-700">${LPC.fmt.int(disc)} F</span>`
                        : '<span class="text-gray-300">—</span>')}</td>
                    <td class="py-3 px-4 text-right">${LPC.raw(surch > 0
                        ? `<span class="font-black text-emerald-700">${LPC.fmt.int(surch)} F</span>`
                        : '<span class="text-gray-300">—</span>')}</td>
                    <td class="py-3 px-6 text-right font-black text-lpc-dark">${LPC.fmt.int(l.line_total)} F</td>
                </tr>`;
        });
    }

    function renderDeliveries(dels) {
        const box = document.getElementById('so-deliveries');
        box.innerHTML = '';
        if (!dels.length) {
            box.innerHTML = '<p class="text-sm text-gray-400 italic text-center py-6">Aucun BL généré pour cette commande.</p>';
            return;
        }
        dels.forEach(d => {
            const m = DELIVERY_MODES[d.delivery_mode] || DELIVERY_MODES.own_fleet;
            const who = d.delivery_mode === 'supplier' ? (d.supplier_name || '—')
                      : d.delivery_mode === 'client_pickup' ? 'Véhicule client'
                      : (d.driver_name || '—');
            const signed = d.signed_at
                ? LPC.html`<span class="text-[10px] font-bold text-emerald-600"><i class="fas fa-signature mr-1"></i>Signé ${d.signatory_name ? 'par ' + d.signatory_name : ''}</span>`
                : '<span class="text-[10px] font-bold text-gray-400">Non signé</span>';

            box.innerHTML += LPC.html`
                <a href="/modules/sales/bl_detail.php?id=${d.id}"
                   class="block border border-gray-200 rounded-xl p-4 hover:border-lpc-dark hover:shadow-sm transition-all">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-black text-blue-700">${d.reference}</span>
                        <span class="text-xs font-bold text-gray-500">${LPC.fmt.date(d.date)}</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs font-bold text-gray-600 mb-1">
                        <i class="fas ${m.icon} text-gray-400"></i> ${m.short} · ${who}
                    </div>
                    <div class="flex items-center justify-between">
                        ${LPC.raw(signed)}
                        ${LPC.raw(Number(d.payment_collected) > 0
                            ? `<span class="text-[10px] font-black text-emerald-700">${LPC.fmt.int(d.payment_collected)} F encaissés</span>`
                            : '')}
                    </div>
                </a>`;
        });
    }

    function renderInvoices(invs) {
        const box = document.getElementById('so-invoices');
        box.innerHTML = '';
        if (!invs.length) {
            box.innerHTML = '<p class="text-sm text-gray-400 italic text-center py-6">Aucune facture émise.</p>'
                + '<div class="text-center"><a href="/modules/accounting/invoices.php#to_invoice" class="inline-block px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-black text-xs uppercase tracking-widest">Aller à la facturation</a></div>';
            return;
        }
        invs.forEach(i => {
            const paid = Number(i.paid_amount) || 0;
            const total = Number(i.total_amount) || 0;
            const due = Math.max(0, total - paid);
            const pct = total > 0 ? Math.min(100, Math.round(paid / total * 100)) : 0;
            const overdue = due > 0 && i.due_date && new Date(i.due_date) < new Date();

            box.innerHTML += LPC.html`
                <div class="border border-gray-200 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-black text-gray-900">${i.reference}</span>
                        <span class="text-xs font-bold text-gray-500">${LPC.fmt.date(i.date)}</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden mb-2">
                        <div class="${pct >= 100 ? 'bg-emerald-500' : 'bg-amber-500'} h-2" style="width:${pct}%"></div>
                    </div>
                    <div class="flex items-center justify-between text-xs font-bold">
                        <span class="text-gray-600">${LPC.fmt.int(paid)} / ${LPC.fmt.int(total)} F</span>
                        ${LPC.raw(due > 0
                            ? `<span class="${overdue ? 'text-red-600' : 'text-amber-600'}">${LPC.fmt.int(due)} F dû${overdue ? ' · en retard' : ''}</span>`
                            : '<span class="text-emerald-600"><i class="fas fa-check-circle"></i> Soldée</span>')}
                    </div>
                </div>`;
        });
    }

    function renderPriceChanges(changes) {
        const card = document.getElementById('so-price-changes-card');
        const body = document.getElementById('so-price-changes-body');
        if (!changes || !changes.length) { card.classList.add('hidden'); return; }

        body.innerHTML = '';
        changes.forEach(c => {
            body.innerHTML += LPC.html`
                <tr class="hover:bg-gray-50">
                    <td class="py-3 px-6 font-bold text-gray-900">${c.product_name}</td>
                    <td class="py-3 px-4 text-right font-bold text-gray-500">${c.old_price === null ? '—' : LPC.fmt.int(c.old_price) + ' F'}</td>
                    <td class="py-3 px-4 text-right font-black text-blue-700">${LPC.fmt.int(c.new_price)} F</td>
                    <td class="py-3 px-4 text-xs font-bold text-gray-600">${c.changed_by_name || '—'}</td>
                    <td class="py-3 px-6 text-xs font-bold text-gray-500">${LPC.fmt.dateTime(c.changed_at)}</td>
                </tr>`;
        });
    }

    function renderHeaderActions(o, d) {
        const box = document.getElementById('detail-header-actions');
        let html = '';

        if (o.status === 'pending') {
            html += `<a href="/modules/sales/orders.php" class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm shadow-sm flex items-center gap-2"><i class="fas fa-truck-loading"></i> Générer un BL</a>`;
        }
        if (o.status !== 'cancelled' && (d.deliveries || []).length === 0) {
            html += `<button onclick="window.LPC_cancelOrder()" data-perm="sales.orders.cancel" class="px-4 py-2.5 bg-white border border-red-200 text-red-600 hover:bg-red-50 rounded-xl font-bold text-sm flex items-center gap-2"><i class="fas fa-ban"></i> Annuler</button>`;
        }
        box.innerHTML = html;
        // Re-run the RBAC gates over the markup we just injected, or the
        // data-perm attributes above would be inert — lpc-rbac.js only sweeps
        // nodes present when it ran plus whatever its MutationObserver catches.
        if (window.LPC && typeof window.LPC.applyGates === 'function') {
            window.LPC.applyGates(box);
        }
    }

    // Exposed for the header button. Cancellation reuses the same endpoint and
    // the same required motif as the list page — there is one rule, not two.
    window.LPC_cancelOrder = async function () {
        // Second arg is an options object, not a default value — see the
        // signature in assets/js/lpc-modal.js.
        const reason = await LPC.modal.prompt("Motif de l'annulation :", {
            title: 'Annuler la commande',
            placeholder: "ex: Client s'est rétracté",
            required: true,
        });
        if (reason === null) return;
        if (!String(reason).trim()) return LPC.modal.alert("Motif obligatoire.");

        try {
            const res = await fetch('/api/v1/sales_controller.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cancel_order', id: SO_ID, reason: String(reason).trim() })
            });
            const r = await res.json();
            if (r.status === 'success') location.reload();
            else LPC.modal.alert(r.message || 'Erreur.');
        } catch (e) { LPC.modal.alert('Erreur serveur.'); }
    };
})();
