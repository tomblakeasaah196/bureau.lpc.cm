/**
 * assets/js/modules/inventory-po_detail.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — full-page Purchase Order detail (modules/inventory/po_detail.php).
 *
 * Everything about one PO in one place: header info, delivery place, reception
 * progress (per line and overall), the full reception timeline, and the
 * ristourne this specific order earned/consumed — reading api/v1/procurement_
 * controller.php?action=get_po_detail, added alongside this page.
 *
 * The "Recevoir" action does not duplicate the reception form here — it deep
 * links to the existing Stock & Emballages > Réceptions tab
 * (modules/inventory/stock.php?receive_po=<id>), which auto-opens the
 * reception modal for this exact PO and gets a "Retour à la commande" button
 * so the operator can come straight back to this page afterwards.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    const poId = window.LPC_PO_ID;

    document.addEventListener('DOMContentLoaded', () => {
        if (!poId) {
            showError('Bon de commande non spécifié.');
            return;
        }
        loadDetail();
    });

    function showError(msg) {
        document.getElementById('po-loading').classList.add('hidden');
        const err = document.getElementById('po-error');
        err.textContent = msg;
        err.classList.remove('hidden');
    }

    async function loadDetail() {
        try {
            const res = await fetch(`/api/v1/procurement_controller.php?action=get_po_detail&id=${poId}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message || 'Erreur inconnue');
            render(result.data);
        } catch (e) {
            showError('Impossible de charger cette commande : ' + e.message);
        }
    }

    function statusBadge(status) {
        const map = {
            pending:   ['bg-blue-50 text-blue-700',    'fa-clock',        'En attente de réception'],
            partial:   ['bg-amber-50 text-amber-700',  'fa-truck-loading','Réception partielle'],
            received:  ['bg-emerald-50 text-emerald-700','fa-check-circle','Réceptionné'],
            cancelled: ['bg-gray-100 text-gray-500',   'fa-ban',          'Annulé'],
        };
        const [cls, icon, label] = map[status] || map.pending;
        return `<span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${cls}"><i class="fas ${icon} mr-1"></i>${label}</span>`;
    }

    function paymentBadge(status, cancelled) {
        if (cancelled) return '<span class="text-gray-500"><i class="fas fa-ban mr-1"></i>Annulé</span>';
        return status === 'paid'
            ? '<span class="text-emerald-600"><i class="fas fa-check mr-1"></i>Payé</span>'
            : '<span class="text-red-600"><i class="fas fa-hourglass-half mr-1"></i>À Crédit</span>';
    }

    function render(data) {
        const po = data.po;
        const lines = data.lines;
        const totals = data.totals;
        const cancelled = po.status === 'cancelled';

        document.getElementById('po-loading').classList.add('hidden');
        document.getElementById('po-content').classList.remove('hidden');

        document.getElementById('po-reference').textContent = po.reference;
        document.getElementById('po-status-badge').innerHTML = statusBadge(po.status);
        document.getElementById('po-supplier-name').innerHTML = `<i class="fas fa-industry mr-1 text-gray-300"></i>${po.supplier_name}`;
        document.getElementById('po-total').textContent = LPC.fmt.int(po.total_amount) + ' FCFA';
        document.getElementById('po-date').textContent = LPC.fmt.date(po.date);
        document.getElementById('po-payment-status').innerHTML = paymentBadge(po.payment_status, cancelled);
        document.getElementById('po-delivery-place').textContent = po.delivery_place_name || 'Non spécifié';
        document.getElementById('po-created-by').textContent = (po.created_by_name || '').trim() || '—';
        document.getElementById('po-rebate-earned').textContent = totals.rebate_earned > 0
            ? LPC.fmt.int(totals.rebate_earned) + ' F'
            : '0 F';

        // Header action buttons: PDF always; Recevoir only if something remains
        // and the order isn't cancelled.
        const actions = document.getElementById('detail-header-actions');
        let actionsHtml = `<a href="/bon_commande.php?token=${po.token}" target="_blank" class="px-4 py-2 bg-white border border-gray-200 rounded-xl font-bold text-sm text-gray-700 shadow-sm hover:bg-gray-50"><i class="fas fa-file-pdf mr-2 text-red-500"></i>Voir PDF</a>`;
        if (!cancelled && totals.qty_remaining > 0) {
            actionsHtml += `<a href="/modules/inventory/stock.php?receive_po=${po.id}#receptions" class="px-4 py-2 bg-lpc-dark hover:bg-green-800 text-white rounded-xl font-bold text-sm shadow-lg shadow-lpc-dark/20"><i class="fas fa-truck-loading mr-2"></i>Recevoir la Marchandise</a>`;
        }
        actions.innerHTML = actionsHtml;

        // Reception progress
        const pct = totals.qty_ordered > 0 ? Math.round((totals.qty_received / totals.qty_ordered) * 100) : 0;
        document.getElementById('reception-progress-bar').style.width = pct + '%';
        document.getElementById('reception-progress-label').textContent =
            `${totals.qty_received} / ${totals.qty_ordered} unités reçues (${totals.qty_remaining} restantes)`;
        const stateMap = {
            complete:  ['bg-emerald-50 text-emerald-700', 'Complète'],
            partial:   ['bg-amber-50 text-amber-700', 'Partielle'],
            pending:   ['bg-blue-50 text-blue-700', 'Pas encore commencée'],
            cancelled: ['bg-gray-100 text-gray-500', 'Annulée'],
        };
        const [stCls, stLabel] = stateMap[data.reception_state] || stateMap.pending;
        document.getElementById('reception-state-badge').innerHTML =
            `<span class="px-3 py-1 rounded-full text-[10px] font-black uppercase ${stCls}">${stLabel}</span>`;

        // Lines table
        const linesBody = document.getElementById('po-lines-body');
        linesBody.innerHTML = lines.map(l => `
            <tr class="hover:bg-gray-50">
                <td class="py-3 px-6 font-bold text-gray-800">${escapeHtml(l.product_name)}</td>
                <td class="py-3 px-4 text-center font-bold text-gray-600">${l.qty_ordered}</td>
                <td class="py-3 px-4 text-center font-black text-emerald-700 bg-emerald-50/30">${l.qty_received}</td>
                <td class="py-3 px-4 text-center font-black ${l.qty_remaining > 0 ? 'text-amber-700 bg-amber-50/30' : 'text-gray-300'}">${l.qty_remaining}</td>
                <td class="py-3 px-4 text-right font-bold text-gray-600">${LPC.fmt.int(l.unit_price)}</td>
                <td class="py-3 px-6 text-right font-black text-gray-900">${LPC.fmt.int(l.line_total)}</td>
                <td class="py-3 px-4 text-right font-bold ${l.rebate_amount_earned > 0 ? 'text-amber-600' : 'text-gray-300'}">
                    ${l.rebate_amount_earned > 0 ? LPC.fmt.int(l.rebate_amount_earned) + ' F' : '—'}
                    ${l.rebate_rate ? `<div class="text-[9px] text-gray-400 font-black">${(l.rebate_rate * 100).toFixed(2)}%</div>` : ''}
                </td>
            </tr>
        `).join('') || `<tr><td colspan="7" class="py-8 text-center text-gray-400 italic">Aucune ligne.</td></tr>`;

        // Reception timeline
        const timeline = document.getElementById('reception-timeline');
        if (!data.receptions.length) {
            timeline.innerHTML = '<p class="text-sm text-gray-400 italic text-center py-6">Aucune réception enregistrée pour le moment.</p>';
        } else {
            timeline.innerHTML = data.receptions.map(r => `
                <div class="flex gap-3 items-start">
                    <div class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 mt-0.5">
                        <i class="fas fa-dolly text-xs"></i>
                    </div>
                    <div class="flex-1 border-b border-gray-100 pb-3">
                        <p class="text-sm font-bold text-gray-800">${escapeHtml(r.product_name)} — <span class="text-emerald-600">+${r.quantity}</span></p>
                        <p class="text-xs text-gray-400 font-bold mt-0.5">${LPC.fmt.date(r.date)} · reçu par ${escapeHtml((r.logged_by_name || '').trim() || 'N/A')}</p>
                    </div>
                </div>
            `).join('');
        }

        // Ristourne ledger for this PO
        const ledger = document.getElementById('po-rebate-ledger');
        if (!data.rebate_ledger.length) {
            ledger.innerHTML = '<p class="text-sm text-gray-400 italic text-center py-6">Aucun mouvement de ristourne pour cette commande.</p>';
        } else {
            ledger.innerHTML = data.rebate_ledger.map(row => {
                const isAccrual = row.type === 'accrual';
                const reversed = Number(row.reversed) === 1;
                const color = reversed ? 'text-gray-400 line-through' : (isAccrual ? 'text-emerald-600' : 'text-rose-600');
                const prefix = isAccrual ? '+' : '-';
                return `
                    <div class="flex justify-between items-start ${reversed ? 'opacity-60' : ''}">
                        <div>
                            <p class="text-xs font-bold text-gray-700">${escapeHtml(row.notes || '')}</p>
                            <p class="text-[10px] text-gray-400 font-black mt-0.5">${LPC.fmt.date(row.date)} · ${escapeHtml(row.reference)}</p>
                        </div>
                        <span class="font-black text-sm ${color}">${prefix} ${LPC.fmt.int(row.amount)}</span>
                    </div>`;
            }).join('');
        }
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }
})();
