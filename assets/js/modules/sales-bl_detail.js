/**
 * assets/js/modules/sales-bl_detail.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — modules/sales/bl_detail.php.
 *
 * A BL had no detail view at all. The dispatch list row was everything: to see
 * which lines a client had short-accepted, whether the driver had signed, or
 * whether the note had been invoiced, you opened the printable PDF and read it.
 *
 * Two things this page is careful about:
 *   · `qty_accepted` is NULL until the delivery is closed out, which is not the
 *     same as zero. Rendering NULL as 0 would show every in-transit BL as a
 *     total rejection.
 *   · Acheminement (fleet / supplier / pickup) is internal. It is shown here
 *     and never on the customer's document — see migration 061.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    const BL_ID = Number(window.LPC_BL_ID || 0);

    const STATUS_BADGES = {
        draft:            '<span class="px-3 py-1 bg-gray-100 text-gray-700 text-[10px] font-black uppercase rounded-lg">Brouillon</span>',
        dispatched:       '<span class="px-3 py-1 bg-blue-100 text-blue-800 text-[10px] font-black uppercase rounded-lg"><i class="fas fa-truck mr-1"></i>En transit</span>',
        driver_confirmed: '<span class="px-3 py-1 bg-amber-100 text-amber-800 text-[10px] font-black uppercase rounded-lg"><i class="fas fa-user-check mr-1"></i>Confirmé chauffeur</span>',
        completed:        '<span class="px-3 py-1 bg-emerald-100 text-emerald-800 text-[10px] font-black uppercase rounded-lg"><i class="fas fa-lock mr-1"></i>Clôturé</span>',
        cancelled:        '<span class="px-3 py-1 bg-red-100 text-red-800 text-[10px] font-black uppercase rounded-lg">Annulé</span>',
    };

    const MODES = {
        own_fleet:     { icon: 'fa-truck',    label: 'Flotte LPC' },
        supplier:      { icon: 'fa-industry', label: 'Livraison fournisseur' },
        client_pickup: { icon: 'fa-store',    label: 'Enlèvement magasin' },
    };

    document.addEventListener('DOMContentLoaded', load);

    async function load() {
        if (!BL_ID) return fail('BL non spécifié.');
        try {
            const res = await fetch(`/api/v1/sales_controller.php?action=get_delivery_detail&id=${BL_ID}`);
            const r = await res.json();
            if (r.status !== 'success') return fail(r.message || 'BL introuvable.');
            render(r.data);
        } catch (e) { fail('Impossible de charger le bon de livraison.'); }
    }

    function fail(msg) {
        document.getElementById('bl-loading').classList.add('hidden');
        const err = document.getElementById('bl-error');
        err.textContent = msg;
        err.classList.remove('hidden');
    }

    function render(d) {
        const b = d.delivery;

        document.getElementById('bl-reference').textContent = b.reference;
        document.getElementById('bl-status-badge').innerHTML = STATUS_BADGES[b.status] || b.status;
        document.getElementById('bl-client-name').textContent =
            b.client_name + (b.client_phone ? ' · ' + b.client_phone : '');
        document.getElementById('bl-cash').textContent = LPC.fmt.fcfa(b.payment_collected);
        document.getElementById('bl-date').textContent = LPC.fmt.date(b.date);
        document.getElementById('bl-created-by').textContent = b.created_by_name || '—';

        document.getElementById('bl-so-ref').innerHTML = b.sales_order_id
            ? LPC.html`<a href="/modules/sales/order_detail.php?id=${b.sales_order_id}" class="text-lpc-dark hover:underline">${b.so_reference || '—'}</a>`
            : '<span class="text-gray-300">—</span>';

        const m = MODES[b.delivery_mode] || MODES.own_fleet;
        const who = b.delivery_mode === 'supplier' ? (b.supplier_name || '—')
                  : b.delivery_mode === 'client_pickup' ? 'Véhicule client'
                  : [b.driver_name, b.plate_number].filter(Boolean).join(' · ') || '—';
        document.getElementById('bl-mode').innerHTML =
            LPC.raw(`<i class="fas ${m.icon} text-gray-400 mr-1"></i>`) + LPC.html`${m.label}`
            + LPC.raw(`<span class="block text-[10px] font-bold text-gray-500">${LPC.escapeHtml(who)}</span>`);

        document.getElementById('bl-invoice').innerHTML = b.invoice_reference
            ? LPC.html`<a href="/modules/accounting/invoices.php#invoices" class="text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-2 py-1 rounded text-[10px] font-black">${b.invoice_reference}</a>`
            : (b.status === 'completed'
                ? '<a href="/modules/accounting/invoices.php#to_invoice" class="text-amber-700 bg-amber-50 hover:bg-amber-100 px-2 py-1 rounded text-[10px] font-black uppercase">À facturer</a>'
                : '<span class="text-gray-300">—</span>');

        if (b.rejection_reason) {
            document.getElementById('bl-rejection-banner').classList.remove('hidden');
            document.getElementById('bl-rejection-reason').textContent = b.rejection_reason;
        }

        renderSignatures(b);
        renderLines(d.items, d.short_total);
        renderHeaderActions(b);

        document.getElementById('bl-loading').classList.add('hidden');
        document.getElementById('bl-content').classList.remove('hidden');
    }

    function renderSignatures(b) {
        const box = document.getElementById('bl-signatures');
        const card = (title, icon, signed, name, at, hash) => `
            <div class="border ${signed ? 'border-emerald-200 bg-emerald-50/40' : 'border-gray-200'} rounded-xl p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[10px] font-black uppercase tracking-widest ${signed ? 'text-emerald-800' : 'text-gray-500'}">
                        <i class="fas ${icon} mr-1"></i>${title}
                    </span>
                    ${signed
                        ? '<span class="text-[10px] font-black text-emerald-700"><i class="fas fa-check-circle"></i> Signé</span>'
                        : '<span class="text-[10px] font-black text-gray-400">En attente</span>'}
                </div>
                <p class="text-sm font-bold text-gray-800">${LPC.escapeHtml(name || '—')}</p>
                <p class="text-[10px] font-bold text-gray-500">${at ? LPC.fmt.dateTime(at) : '—'}</p>
                ${hash ? `<p class="text-[9px] font-mono text-gray-400 mt-1 truncate" title="${LPC.escapeHtml(hash)}">${LPC.escapeHtml(String(hash).slice(0, 32))}…</p>` : ''}
            </div>`;

        // The signature images themselves are deliberately not sent by the API —
        // they are large base64 blobs and this is a summary page. Presence is
        // what matters here; the image lives on the printable BL.
        box.innerHTML =
            card('Chauffeur', 'fa-truck', !!b.driver_signed_at, b.driver_name, b.driver_signed_at, b.driver_digital_hash) +
            card('Client', 'fa-user-check', !!b.signed_at,
                 [b.signatory_name, b.signatory_role].filter(Boolean).join(' — '), b.signed_at, b.digital_hash);
    }

    function renderLines(items, shortTotal) {
        const body = document.getElementById('bl-lines-body');
        body.innerHTML = '';

        if (Number(shortTotal) > 0) {
            const badge = document.getElementById('bl-short-badge');
            badge.textContent = `${shortTotal} unité(s) non acceptée(s)`;
            badge.classList.remove('hidden');
        }

        if (!items.length) {
            body.innerHTML = '<tr><td colspan="6" class="py-6 text-center text-gray-400 italic">Aucune ligne.</td></tr>';
            return;
        }

        items.forEach(it => {
            // NULL accepted = not yet closed out. Rendered as "—", never as 0:
            // a BL still on the road has not rejected anything.
            const acceptedCell = it.qty_accepted === null
                ? '<span class="text-gray-400 italic text-xs">en cours</span>'
                : `<span class="font-black text-emerald-700">${it.qty_accepted}</span>`;
            const shortCell = it.qty_short === null
                ? '<span class="text-gray-300">—</span>'
                : (it.qty_short > 0
                    ? `<span class="font-black text-red-600">−${it.qty_short}</span>`
                    : '<span class="text-emerald-500"><i class="fas fa-check"></i></span>');
            const emptyCell = it.empty_name
                ? `<span class="font-black text-amber-700">${Number(it.returned_empty_qty) || 0}</span>`
                  + `<span class="block text-[9px] font-bold text-amber-600">${LPC.escapeHtml(it.empty_name)}</span>`
                : '<span class="text-gray-300">—</span>';

            body.innerHTML += LPC.html`
                <tr class="hover:bg-gray-50">
                    <td class="py-3 px-6">
                        <p class="font-bold text-gray-900">${it.product_name}</p>
                        <p class="text-[10px] font-bold text-gray-400">${it.format || ''}</p>
                    </td>
                    <td class="py-3 px-4 text-center font-black text-gray-800">${it.qty_shipped}</td>
                    <td class="py-3 px-4 text-center bg-emerald-50/40">${LPC.raw(acceptedCell)}</td>
                    <td class="py-3 px-4 text-center bg-red-50/40">${LPC.raw(shortCell)}</td>
                    <td class="py-3 px-4 text-center bg-amber-50/40">${LPC.raw(emptyCell)}</td>
                    <td class="py-3 px-6 text-right font-bold text-gray-700">${LPC.fmt.int(it.unit_price)} F</td>
                </tr>`;
        });
    }

    function renderHeaderActions(b) {
        const box = document.getElementById('detail-header-actions');
        let html = LPC.html`<a href="/bon_livraison.php?token=${b.token}" target="_blank"
            class="px-4 py-2.5 bg-white border border-gray-200 text-gray-700 hover:border-lpc-dark rounded-xl font-bold text-sm flex items-center gap-2"><i class="fas fa-print"></i> Imprimer le BL</a>`;

        if (b.status === 'completed' && !b.invoice_reference) {
            html += `<a href="/modules/accounting/invoices.php#to_invoice" data-perm="accounting.invoices.create"
                class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-sm shadow-sm flex items-center gap-2"><i class="fas fa-file-invoice-dollar"></i> Facturer</a>`;
        }
        box.innerHTML = html;
        if (window.LPC && typeof window.LPC.applyGates === 'function') window.LPC.applyGates(box);
    }
})();
