/**
 * assets/js/modules/accounting-tax_declarations.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 rewrite for full monthly compliance.
 *
 * WHAT'S NEW vs the Sprint 6 D2 version:
 *   • Bulletproof loaders — every fetch renders an error state instead of
 *     leaving "Chargement…" hanging when the API fails.
 *   • Master Monthly Modal — click any month in the calendar and see every
 *     declaration (TVA + AIR + DIPE + CNPS + TSR + Commune) with the
 *     attestations that reduced AIR, the CNPS/DGI/commune split, the
 *     approval button, PDF snapshot download, and payment recording.
 *   • Calendar grid across the fiscal year with a Status chip per row.
 *   • Approve → lock → PDF; Record payment → treasury JE + quittance.
 *   • Deeplinks + configuration snapshot in the Paramètres tab.
 * -----------------------------------------------------------------------------
 */

// D2 hoister prelude — populate window.PAGE_DATA from the inert JSON block.
(function () {
    if (window.PAGE_DATA) return;
    const el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

const API = '/api/v1/tax_controller.php';

const KIND_META = {
    tva:       { label: 'TVA',      chip: 'kind-tva',     destination: 'dgi',     full: 'TVA — 19,25 %' },
    air:       { label: 'AIR',      chip: 'kind-air',     destination: 'dgi',     full: 'AIR — Acompte d\'IS (2,2 %)' },
    irpp_dipe: { label: 'DIPE',     chip: 'kind-dipe',    destination: 'dgi',     full: 'DIPE — IRPP + CAC + CFC + CRTV + TDL + FNE' },
    cnps:      { label: 'CNPS',     chip: 'kind-cnps',    destination: 'cnps',    full: 'CNPS — PVID + PF + AT' },
    tsr:       { label: 'TSR',      chip: 'kind-tsr',     destination: 'dgi',     full: 'TSR — services étrangers' },
    commune:   { label: 'COMMUNE',  chip: 'kind-commune', destination: 'commune', full: 'Taxe communale mensuelle' },
    patente:   { label: 'PATENTE',  chip: 'kind-commune', destination: 'commune', full: 'Patente annuelle' },
};

const MONTH_FR = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
const MONTH_FR_SHORT = ['','janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmt(n) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    if (window.LPC && LPC.fmt && typeof LPC.fmt.fcfa === 'function') return LPC.fmt.fcfa(n);
    // Fallback formatter so loading NEVER dies just because lpc-dom.js was
    // late to the party.
    try {
        return Math.round(Number(n)).toLocaleString('fr-FR').replace(/,/g, ' ') + ' FCFA';
    } catch (_e) { return String(n) + ' FCFA'; }
}
function toast(msg, level) {
    if (window.LPC && LPC.toast && typeof LPC.toast[level || 'info'] === 'function') {
        LPC.toast[level || 'info'](msg);
    } else {
        try { console[level === 'error' ? 'error' : 'log'](msg); } catch (e) {}
        // Silent-fail on old shells — the toast is a UX polish, not a blocker.
    }
}
function confirmDlg(msg, opts) {
    if (window.LPC && LPC.modal && typeof LPC.modal.confirm === 'function') return LPC.modal.confirm(msg, opts || {});
    return Promise.resolve(window.confirm(msg));
}
function alertDlg(msg, opts) {
    if (window.LPC && LPC.modal && typeof LPC.modal.alert === 'function') return LPC.modal.alert(msg, opts || {});
    alert(msg); return Promise.resolve();
}

async function post(action, payload) {
    try {
        const r = await fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action, ...payload}) });
        return await r.json();
    } catch (e) {
        console.error('POST', action, e);
        return { status: 'error', message: e.message || 'Erreur réseau' };
    }
}
async function get(qs) {
    try {
        const r = await fetch(API + '?' + qs);
        return await r.json();
    } catch (e) {
        console.error('GET', qs, e);
        return { status: 'error', message: e.message || 'Erreur réseau' };
    }
}

function switchTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    const target = document.getElementById('content-' + name);
    if (target) target.classList.add('active');
    document.querySelectorAll('.tab-link').forEach(el => {
        el.className = el.className.replace('border-emerald-600 text-emerald-700 font-black', 'border-transparent text-gray-400 font-bold');
    });
    const btn = document.getElementById('tab-' + name);
    if (btn) btn.className = btn.className.replace('border-transparent text-gray-400 font-bold', 'border-emerald-600 text-emerald-700 font-black');
    if (name === 'dashboard')   loadDashboard();
    if (name === 'monthly')   { loadMonthGrid(); loadHistory(); }
    if (name === 'withholding'){ loadClients(); loadCertificates(); }
    if (name === 'settings')  loadSettings();
}

function currentYear() {
    const sel = document.getElementById('tax-year');
    return sel ? parseInt(sel.value, 10) : new Date().getFullYear();
}

// ============================================================
// DASHBOARD (Échéances)
// ============================================================

async function loadPendingAttestations() {
    const card = document.getElementById('pending-attest-card');
    if (!card) return;
    const r = await get('action=pending_attestations');
    if (r.status !== 'success' || !r.data || !Array.isArray(r.data.rows) || r.data.rows.length === 0) {
        card.classList.add('hidden');
        return;
    }
    card.classList.remove('hidden');
    document.getElementById('pending-attest-total').textContent = fmt(r.data.total_pending);
    document.getElementById('pending-attest-subline').textContent =
        `${r.data.client_count} client(s) × ${r.data.rows.length} période(s) — attestations à réclamer pour créditer ces retenues.`;
    document.getElementById('pending-attest-body').innerHTML = r.data.rows.map(row => {
        const period = `${MONTH_FR_SHORT[row.period_month] || row.period_month} ${row.period_year}`;
        const qs = `action=attestation_request&client_id=${encodeURIComponent(row.client_id)}&period_year=${encodeURIComponent(row.period_year)}&period_month=${encodeURIComponent(row.period_month)}`;
        return `<tr>
            <td class="px-3 py-2 font-bold text-amber-900">${esc(row.client_name)}</td>
            <td class="px-3 py-2 text-amber-800">${period}</td>
            <td class="px-3 py-2 text-right text-amber-800">${row.payment_count}</td>
            <td class="px-3 py-2 text-right amt font-black text-amber-900">${fmt(row.air_pending)}</td>
            <td class="px-3 py-2 text-right whitespace-nowrap">
                <a href="${API}?${qs}&format=html" target="_blank" class="text-xs font-bold text-amber-800 hover:text-amber-950 mr-3"><i class="fas fa-eye"></i> Aperçu</a>
                <a href="${API}?${qs}&format=pdf" class="text-xs font-black text-white bg-amber-700 hover:bg-amber-900 px-3 py-1.5 rounded uppercase tracking-wider"><i class="fas fa-file-pdf mr-1"></i>Demander (PDF)</a>
            </td>
        </tr>`;
    }).join('');
}

async function loadDashboard() {
    const body = document.getElementById('upcoming-body');
    if (body) body.innerHTML = `<tr><td colspan="8" class="px-6 py-8 text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement…</td></tr>`;
    loadPendingAttestations();

    const r = await get('action=dashboard');
    if (r.status !== 'success') {
        if (body) body.innerHTML = `<tr><td colspan="8" class="px-6 py-8 text-center text-red-600 font-bold">Erreur : ${esc(r.message || 'chargement du dashboard échoué')}</td></tr>`;
        return;
    }
    const rows = (r.data.upcoming || [])
        .filter(x => (x.net > 0 || x.total_due > 0 || x.error))
        .sort((a, b) => (a.due_date || '').localeCompare(b.due_date || ''));

    if (rows.length) {
        const first = rows[0];
        document.getElementById('kpi_next_due').textContent = (first.kind || '').toUpperCase() + ' · ' + (first.due_date || '');
        document.getElementById('kpi_next_amount').textContent = fmt(rows.reduce((s, x) => s + (x.net || 0), 0));
    } else {
        document.getElementById('kpi_next_due').textContent = '—';
        document.getElementById('kpi_next_amount').textContent = fmt(0);
    }
    const airRow = rows.find(x => x.kind === 'air');
    document.getElementById('kpi_air_credit').textContent = airRow ? fmt(airRow.credit) : fmt(0);

    const meta = k => KIND_META[k] || {label: k, chip: '', destination: '—'};
    body.innerHTML = rows.map(r => {
        const m = meta(r.kind);
        if (r.error) {
            return `<tr><td colspan="8" class="px-6 py-3 text-xs text-red-600"><i class="fas fa-triangle-exclamation mr-2"></i>${esc(m.label)} ${r.year}-${String(r.month).padStart(2,'0')} : ${esc(r.error)}</td></tr>`;
        }
        return `
        <tr>
            <td class="px-6 py-3 font-bold uppercase text-xs"><span class="kind-chip ${m.chip}">${esc(m.label)}</span></td>
            <td class="px-6 py-3">${r.year}-${String(r.month).padStart(2,'0')}</td>
            <td class="px-6 py-3 text-xs uppercase text-gray-500">${esc(r.destination || m.destination)}</td>
            <td class="px-6 py-3 text-right amt">${fmt(r.total_due)}</td>
            <td class="px-6 py-3 text-right amt text-emerald-700">${fmt(r.credit)}</td>
            <td class="px-6 py-3 text-right amt font-black text-red-600">${fmt(r.net)}</td>
            <td class="px-6 py-3">${esc(r.due_date || '')}</td>
            <td class="px-6 py-3 text-right">
                <button onclick="openMonthlyModal(${r.year}, ${r.month})" class="text-emerald-700 hover:text-emerald-900 font-bold text-xs uppercase"><i class="fas fa-eye mr-1"></i>Voir le mois</button>
            </td>
        </tr>`;
    }).join('') || `<tr><td colspan="8" class="px-6 py-8 text-center text-gray-400">Aucune échéance à venir.</td></tr>`;
}

// ============================================================
// MONTH GRID (Déclarations mensuelles)
// ============================================================

async function loadMonthGrid() {
    const year = currentYear();
    document.getElementById('mg_year').textContent = year;
    const body = document.getElementById('month-grid-body');
    body.innerHTML = `<tr><td colspan="7" class="px-6 py-8 text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement du calendrier…</td></tr>`;

    // Compute the 12 months in parallel — one monthly_summary each.
    const months = [];
    for (let m = 1; m <= 12; m++) months.push(m);
    const results = await Promise.all(months.map(m =>
        post('monthly_summary', {year, month: m}).then(r => ({month: m, ok: r.status === 'success', data: r.data, message: r.message}))
    ));

    const now = new Date();
    const currentY = now.getFullYear(), currentM = now.getMonth() + 1;

    body.innerHTML = results.map(res => {
        const m = res.month;
        const isPast = (year < currentY) || (year === currentY && m < currentM);
        if (!res.ok || !res.data) {
            return `<tr><td colspan="7" class="px-6 py-3 text-xs text-red-600">${MONTH_FR[m]} ${year} — erreur : ${esc(res.message || 'inconnue')}</td></tr>`;
        }
        const d = res.data;
        const locks = d.locks || {};
        const chips = Object.keys(KIND_META).filter(k => k !== 'patente').map(kind => {
            const meta = KIND_META[kind];
            const persist = locks[kind];
            let cls = 'st-missing', label = meta.label;
            if (persist) {
                if (persist.status === 'paid')      cls = 'st-paid';
                else if (persist.locked_at)         cls = 'st-locked';
                else if (persist.status === 'filed')cls = 'st-filed';
                else if (persist.status === 'ready')cls = 'st-ready';
            }
            return `<span class="badge-status ${cls}" title="${esc(meta.full)}">${label}</span>`;
        }).join(' ');

        const dgi = (d.totals && d.totals.dgi) || 0;
        const cnps = (d.totals && d.totals.cnps) || 0;
        const commune = (d.totals && d.totals.commune) || 0;
        const grand = (d.totals && d.totals.grand) || 0;

        const rowCls = grand === 0 ? '' : (isPast ? 'late' : 'done');
        return `
        <tr class="month-cell ${rowCls}" onclick="openMonthlyModal(${year}, ${m})">
            <td class="px-6 py-3 font-black text-gray-900">${MONTH_FR[m]} ${year}</td>
            <td class="px-6 py-3">${chips}</td>
            <td class="px-6 py-3 text-right amt">${fmt(dgi)}</td>
            <td class="px-6 py-3 text-right amt">${fmt(cnps)}</td>
            <td class="px-6 py-3 text-right amt">${fmt(commune)}</td>
            <td class="px-6 py-3 text-right amt font-black">${fmt(grand)}</td>
            <td class="px-6 py-3 text-xs text-gray-500">${esc(d.due_date || '')}</td>
        </tr>`;
    }).join('');
}

// ============================================================
// MASTER MONTHLY MODAL
// ============================================================

let currentMonthlyData = null;

async function openMonthlyModal(year, month) {
    const modal = document.getElementById('tax-monthly-modal');
    const body  = document.getElementById('tmm-body');
    document.getElementById('tmm-title').textContent = `${MONTH_FR[month]} ${year}`;
    document.getElementById('tmm-subtitle').textContent = 'Chargement du récapitulatif…';
    ['tmm-dgi','tmm-cnps','tmm-commune','tmm-grand'].forEach(id => document.getElementById(id).textContent = '—');
    body.innerHTML = `<p class="text-sm text-gray-400 text-center py-12"><i class="fas fa-spinner fa-spin mr-2"></i>Recalcul en cours…</p>`;
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    const r = await post('monthly_summary', {year, month});
    if (r.status !== 'success') {
        body.innerHTML = `<p class="text-red-600 font-bold text-center py-8">${esc(r.message || 'Chargement échoué')}</p>`;
        return;
    }
    currentMonthlyData = { year, month, data: r.data };
    renderMonthlyModal();
}

function closeMonthlyModal() {
    document.getElementById('tax-monthly-modal').classList.remove('open');
    document.body.style.overflow = '';
    currentMonthlyData = null;
    // Reload the month grid so newly-approved statuses show without a manual refresh.
    if (document.getElementById('content-monthly').classList.contains('active')) {
        loadMonthGrid();
        loadHistory();
    }
    if (document.getElementById('content-dashboard').classList.contains('active')) {
        loadDashboard();
    }
}

function renderMonthlyModal() {
    if (!currentMonthlyData) return;
    const {year, month, data} = currentMonthlyData;
    const t = data.totals || {};
    document.getElementById('tmm-dgi').textContent     = fmt(t.dgi || 0);
    document.getElementById('tmm-cnps').textContent    = fmt(t.cnps || 0);
    document.getElementById('tmm-commune').textContent = fmt(t.commune || 0);
    document.getElementById('tmm-grand').textContent   = fmt(t.grand || 0);
    document.getElementById('tmm-subtitle').textContent =
        `Échéance : ${data.due_date || '—'} — cliquez sur « Approuver » pour verrouiller la déclaration et générer le PDF.`;

    const blocks = data.blocks || {};
    const locks  = data.locks  || {};
    const order = ['air','tva','irpp_dipe','cnps','tsr','commune'];

    document.getElementById('tmm-body').innerHTML = order.filter(k => blocks[k]).map(kind => {
        const meta = KIND_META[kind];
        const block = blocks[kind];
        const lock  = locks[kind];
        return renderKindSection(year, month, kind, meta, block, lock);
    }).join('');
}

function renderKindSection(year, month, kind, meta, block, lock) {
    const linesHtml = (block.lines || []).map(l => {
        const cls  = l.informational ? 'info-line' : (l.is_credit ? 'credit-line' : '');
        const sign = l.is_credit ? '−' : '';
        const val  = l.unit === '%' ? (Number(l.amount).toFixed(2) + ' %') : (sign + fmt(l.amount));
        const src  = l.source_ref ? `<div class="text-[10px] text-gray-400 mt-0.5">${esc(l.source_ref)}</div>` : '';
        return `<tr class="${cls}"><td style="width:22%" class="font-mono text-xs">${esc(l.line_code)}</td><td>${esc(l.label)}${src}</td><td class="text-right amt">${esc(val)}</td></tr>`;
    }).join('');

    let attestationsHtml = '';
    if (kind === 'air') {
        const list = block.attestations || [];
        if (list.length) {
            attestationsHtml = `
                <div class="mt-3 p-3 rounded-lg bg-emerald-50 border border-emerald-200">
                    <p class="text-[10px] font-black uppercase text-emerald-700 tracking-widest mb-2">Attestations reçues appliquées à ce mois</p>
                    <table class="w-full text-xs">
                        <thead><tr class="text-left text-emerald-900"><th class="py-1">Client</th><th class="py-1">N° attestation</th><th class="py-1">Date</th><th class="py-1 text-right">AIR crédité</th><th class="py-1 text-right">PDF</th></tr></thead>
                        <tbody class="divide-y divide-emerald-100">
                        ${list.map(a => `<tr>
                            <td class="py-1 font-bold text-emerald-900">${esc(a.client_name)}</td>
                            <td class="py-1">${esc(a.certificate_ref || '')}</td>
                            <td class="py-1">${esc(a.issue_date || '')}</td>
                            <td class="py-1 text-right amt font-black text-emerald-800">${fmt(a.air_withheld)}</td>
                            <td class="py-1 text-right">${a.file_path ? `<a href="/${esc(a.file_path)}" target="_blank" class="text-emerald-700 hover:text-emerald-900"><i class="fas fa-file-pdf"></i></a>` : '—'}</td>
                        </tr>`).join('')}
                        </tbody>
                    </table>
                </div>`;
        } else if ((block.pending_amount || 0) > 0) {
            attestationsHtml = `
                <div class="mt-3 p-3 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-900">
                    <i class="fas fa-exclamation-triangle mr-1"></i>${block.pending_count} paiement(s) totalisant <strong class="amt">${fmt(block.pending_amount)}</strong> ont été retenus à la source mais sans attestation reçue. Ce crédit sera imputé au mois où l'attestation arrive.
                </div>`;
        }
    }

    // Header status + action buttons.
    let statusBadge = '<span class="badge-status st-missing">à faire</span>';
    let approveBtn = `<button onclick="approveDeclaration('${kind}', ${year}, ${month})" class="text-xs font-black text-white bg-emerald-600 hover:bg-emerald-700 rounded-md px-3 py-1.5 uppercase tracking-wider"><i class="fas fa-lock mr-1"></i>Approuver &amp; verrouiller</button>`;
    let payBtn = '';
    let pdfBtn = '';
    let quittanceBtn = '';
    if (lock) {
        if (lock.status === 'paid')      statusBadge = '<span class="badge-status st-paid">payée</span>';
        else if (lock.locked_at)         statusBadge = '<span class="badge-status st-locked">approuvée</span>';
        else if (lock.status === 'filed')statusBadge = '<span class="badge-status st-filed">déclarée</span>';
        else if (lock.status === 'ready')statusBadge = '<span class="badge-status st-ready">calculée</span>';
        if (lock.locked_at) {
            approveBtn = `<span class="text-xs text-purple-700 font-bold" title="Verrouillée le ${esc(lock.locked_at)}"><i class="fas fa-lock mr-1"></i>Verrouillée</span>`;
        }
        if (lock.snapshot_pdf_path) {
            pdfBtn = `<a href="${API}?action=snapshot_pdf&id=${lock.id}" target="_blank" class="text-xs font-bold text-blue-700 hover:text-blue-900 uppercase tracking-wider"><i class="fas fa-file-pdf mr-1"></i>PDF snapshot</a>`;
        }
        if (lock.locked_at && lock.status !== 'paid') {
            payBtn = `<button onclick="openPaymentDialog(${lock.id}, '${kind}', ${block.net})" class="text-xs font-black text-white bg-blue-600 hover:bg-blue-700 rounded-md px-3 py-1.5 uppercase tracking-wider"><i class="fas fa-money-bill-transfer mr-1"></i>Enregistrer paiement</button>`;
        }
        if (lock.quittance_path) {
            quittanceBtn = `<a href="${API}?action=quittance_stream&id=${lock.id}" target="_blank" class="text-xs font-bold text-emerald-700 hover:text-emerald-900 uppercase tracking-wider"><i class="fas fa-receipt mr-1"></i>Quittance</a>`;
        }
    }

    return `
    <section class="kind-section">
        <header>
            <div class="flex items-center gap-3 min-w-0">
                <span class="kind-chip ${meta.chip}">${esc(meta.label)}</span>
                <h4 class="font-black text-gray-900 truncate">${esc(meta.full)}</h4>
                ${statusBadge}
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                ${approveBtn}${pdfBtn ? '<span class="mx-1"></span>' + pdfBtn : ''}${payBtn ? '<span class="mx-1"></span>' + payBtn : ''}${quittanceBtn ? '<span class="mx-1"></span>' + quittanceBtn : ''}
            </div>
        </header>
        <div class="content">
            <table class="lines"><tbody>${linesHtml}</tbody></table>
            ${attestationsHtml}
            <div class="totals mt-3 rounded-lg">
                <span class="text-xs text-gray-500 mr-4">Brut : <span class="amt font-black text-gray-900">${fmt(block.total_due)}</span></span>
                <span class="text-xs text-gray-500 mr-4">Crédit : <span class="amt font-black text-emerald-700">${fmt(block.credit)}</span></span>
                <span class="text-xs text-gray-500">Net à payer : <span class="amt text-red-600 text-lg">${fmt(block.net)}</span></span>
            </div>
        </div>
    </section>`;
}

async function approveDeclaration(kind, year, month) {
    const ok = await confirmDlg(
        `Vous êtes sur le point d'approuver la déclaration ${(KIND_META[kind] || {label: kind}).label} ${MONTH_FR[month]} ${year}. Le snapshot sera verrouillé et un PDF horodaté sera généré. Confirmer ?`,
        { title: 'Approuver la déclaration', confirmLabel: 'Approuver & verrouiller' }
    );
    if (!ok) return;
    const r = await post('approve', {kind, year, month});
    if (r.status !== 'success') { await alertDlg(r.message || 'Erreur', {level: 'error'}); return; }
    toast('Déclaration approuvée · PDF snapshot généré.', 'success');
    // Refresh the modal from the server.
    await openMonthlyModal(year, month);
}

async function openPaymentDialog(declarationId, kind, net) {
    // Read the list of treasury accounts so the accountant can pick one.
    const settingsResp = await get('action=settings_read');
    const treasuries = (settingsResp.status === 'success' && settingsResp.data.treasuries) || [];
    if (!treasuries.length) {
        await alertDlg('Aucun compte de trésorerie configuré. Créez-en un dans Trésorerie avant d\'enregistrer le paiement.', {level: 'warn'});
        return;
    }
    const opts = treasuries.map(t => `<option value="${t.id}">${esc(t.name)} — ${esc(t.currency || 'XAF')}</option>`).join('');
    const bodyHtml = `
        <div class="grid grid-cols-1 gap-3 text-sm">
            <label class="text-xs font-bold text-gray-600">Compte débité <select id="_pay_tid" class="border rounded px-2 py-1.5 w-full">${opts}</select></label>
            <label class="text-xs font-bold text-gray-600">Montant payé (FCFA) <input id="_pay_amt" type="number" step="1" value="${Math.round(net)}" class="border rounded px-2 py-1.5 w-full"></label>
            <label class="text-xs font-bold text-gray-600">Référence bancaire / virement <input id="_pay_ref" type="text" placeholder="Ex: DGI-VIR-2026-0812" class="border rounded px-2 py-1.5 w-full"></label>
            <label class="text-xs font-bold text-gray-600">Date de règlement <input id="_pay_date" type="date" value="${new Date().toISOString().substr(0,10)}" class="border rounded px-2 py-1.5 w-full"></label>
            <label class="text-xs font-bold text-gray-600">Quittance (PDF/image) <input id="_pay_file" type="file" accept="application/pdf,image/*" class="border rounded px-2 py-1.5 w-full"></label>
        </div>
    `;
    let doSubmit = false;
    if (window.LPC && LPC.modal && typeof LPC.modal.custom === 'function') {
        const res = await LPC.modal.custom({
            title: 'Enregistrer le paiement',
            bodyHtml,
            buttons: [
                { label: 'Annuler', value: null },
                { label: 'Enregistrer', value: 'ok', variant: 'primary', primary: true },
            ],
        });
        doSubmit = (res === 'ok');
    } else {
        // Fallback modal in-place.
        const div = document.createElement('div');
        div.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;display:flex;align-items:center;justify-content:center';
        div.innerHTML = `<div style="background:white;padding:24px;border-radius:12px;max-width:420px;width:92%"><h3 style="font-weight:800;margin-bottom:12px">Enregistrer le paiement</h3>${bodyHtml}<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px"><button id="_pay_cancel" style="padding:6px 12px">Annuler</button><button id="_pay_ok" style="padding:6px 12px;background:#059669;color:white;border-radius:6px;font-weight:700">Enregistrer</button></div></div>`;
        document.body.appendChild(div);
        await new Promise(resolve => {
            div.querySelector('#_pay_cancel').onclick = () => { doSubmit = false; document.body.removeChild(div); resolve(); };
            div.querySelector('#_pay_ok').onclick     = () => { doSubmit = true; };
            // Keep the modal open while we submit — closed below.
            div.__cleanup = () => { if (div.parentNode) document.body.removeChild(div); };
        });
    }
    if (!doSubmit) return;

    const tid  = parseInt(document.getElementById('_pay_tid').value, 10);
    const amt  = parseFloat(document.getElementById('_pay_amt').value);
    const ref  = document.getElementById('_pay_ref').value.trim();
    const date = document.getElementById('_pay_date').value;
    const file = document.getElementById('_pay_file').files[0] || null;

    const fd = new FormData();
    fd.append('action', 'record_payment');
    fd.append('declaration_id', declarationId);
    fd.append('treasury_account_id', tid);
    fd.append('paid_amount', amt);
    fd.append('paid_reference', ref);
    fd.append('payment_date', date);
    if (file) fd.append('file', file);
    const r = await fetch(API, {method: 'POST', body: fd}).then(r => r.json()).catch(e => ({status:'error', message: e.message}));
    if (r.status !== 'success') { await alertDlg(r.message || 'Erreur', {level: 'error'}); return; }
    toast('Paiement enregistré · écriture au journal ' + (r.je_id ? '#' + r.je_id : 'à repostér manuellement (mapping COA à vérifier)'), 'success');
    if (currentMonthlyData) await openMonthlyModal(currentMonthlyData.year, currentMonthlyData.month);
}

// ============================================================
// HISTORY (below month grid)
// ============================================================

async function loadHistory() {
    const body = document.getElementById('history-body');
    if (body) body.innerHTML = `<tr><td colspan="6" class="px-6 py-8 text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement…</td></tr>`;
    const r = await get('action=list&year=' + currentYear());
    if (r.status !== 'success') { body.innerHTML = `<tr><td colspan="6" class="px-6 py-8 text-center text-red-600">${esc(r.message || 'Erreur')}</td></tr>`; return; }
    const kindLabel = k => (KIND_META[k] || {label: k}).label;
    body.innerHTML = (r.data || []).map(x => {
        const badge = x.status === 'paid' ? 'st-paid'
                    : x.locked_at ? 'st-locked'
                    : x.status === 'filed' ? 'st-filed'
                    : 'st-ready';
        return `
        <tr>
            <td class="px-6 py-3 font-bold uppercase text-xs">${kindLabel(x.kind)}</td>
            <td class="px-6 py-3">${x.period_year}-${String(x.period_month).padStart(2,'0')}</td>
            <td class="px-6 py-3 text-right amt">${fmt(x.total_due)}</td>
            <td class="px-6 py-3 text-right amt font-black">${fmt(x.net_to_pay)}</td>
            <td class="px-6 py-3"><span class="badge-status ${badge}">${x.locked_at ? 'approuvée' : x.status}</span></td>
            <td class="px-6 py-3 text-right whitespace-nowrap">
                <button onclick="openMonthlyModal(${x.period_year}, ${x.period_month})" class="text-emerald-700 hover:text-emerald-900 font-bold text-xs uppercase mr-3"><i class="fas fa-folder-open mr-1"></i>Ouvrir</button>
                ${x.snapshot_pdf_path ? `<a href="${API}?action=snapshot_pdf&id=${x.id}" target="_blank" class="text-blue-700 hover:text-blue-900 font-bold text-xs uppercase"><i class="fas fa-file-pdf mr-1"></i>PDF</a>` : ''}
            </td>
        </tr>`;
    }).join('') || `<tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">Aucune déclaration enregistrée pour ${currentYear()}.</td></tr>`;
}

// ============================================================
// WITHHOLDING — client picker + certificate list
// ============================================================

async function loadClients() {
    const sel = document.getElementById('wc_client');
    try {
        const r = await fetch('/api/v1/invoices_controller.php?action=get_clients').then(r => r.json());
        if (r.status !== 'success') return;
        sel.innerHTML = '<option value="">— Client —</option>' + r.data.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
    } catch (e) { console.error('loadClients', e); }
}

async function loadCertificates() {
    const body = document.getElementById('cert-body');
    if (body) body.innerHTML = `<tr><td colspan="5" class="px-4 py-8 text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement…</td></tr>`;
    const r = await get('action=list_certificates');
    if (r.status !== 'success') { body.innerHTML = `<tr><td colspan="5" class="px-4 py-8 text-center text-red-600">${esc(r.message)}</td></tr>`; return; }
    body.innerHTML = (r.data || []).map(c => `
        <tr>
            <td class="px-4 py-2 font-bold text-gray-900">${esc(c.client_name)}</td>
            <td class="px-4 py-2">${esc(c.certificate_ref)}</td>
            <td class="px-4 py-2">${MONTH_FR_SHORT[c.period_month] || c.period_month} ${c.period_year}</td>
            <td class="px-4 py-2 text-right amt">${fmt(c.total_amount)}</td>
            <td class="px-4 py-2">${c.file_path ? `<a href="/${esc(c.file_path)}" target="_blank" class="text-emerald-700 hover:text-emerald-900"><i class="fas fa-file-pdf mr-1"></i>Voir</a>` : '—'}</td>
        </tr>
    `).join('') || `<tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucune attestation.</td></tr>`;
}

async function uploadCertificate(e) {
    e.preventDefault();
    const fd = new FormData(e.target); fd.append('action', 'upload_certificate');
    const r = await fetch(API, {method: 'POST', body: fd}).then(r => r.json()).catch(err => ({status:'error', message: err.message}));
    if (r.status === 'success') {
        toast('Attestation enregistrée · paiements liés du mois mis à jour.', 'success');
        e.target.reset();
        loadCertificates();
    } else {
        await alertDlg(r.message || 'Erreur', {level: 'error'});
    }
}

// ============================================================
// SETTINGS
// ============================================================

async function loadSettings() {
    const pct = n => (Number(n) * 100).toFixed(2) + ' %';
    // Static section from the inert JSON block.
    document.getElementById('s_regime').textContent    = (window.PAGE_DATA.regime || 'reel').toUpperCase();
    document.getElementById('s_niu').textContent       = window.PAGE_DATA.niu || '—';
    document.getElementById('s_air').textContent       = pct(window.PAGE_DATA.air_rate);
    document.getElementById('s_tva').textContent       = pct(window.PAGE_DATA.tva_rate);
    document.getElementById('s_cit').textContent       = pct(window.PAGE_DATA.cit_rate);
    document.getElementById('s_fne').textContent       = pct(window.PAGE_DATA.fne_rate);
    document.getElementById('s_at').textContent        = pct(window.PAGE_DATA.at_rate);
    document.getElementById('s_cnps').textContent      = 'Groupe ' + window.PAGE_DATA.cnps_group;
    document.getElementById('s_commune').textContent   = fmt(window.PAGE_DATA.commune_monthly || 0);
    document.getElementById('s_filing_day').textContent = 'le ' + window.PAGE_DATA.filing_day + ' du mois suivant';

    // Server-side confirmation (in case the PHP JSON diverged from what
    // the engine actually reads at compute time).
    const r = await get('action=settings_read');
    if (r.status !== 'success' || !r.data || !r.data.settings) return;
    const s = r.data.settings;
    document.getElementById('s_regime').textContent    = String(s.tax_regime || 'reel').toUpperCase();
    document.getElementById('s_niu').textContent       = s.niu || '—';
    document.getElementById('s_air').textContent       = pct(s.air_rate);
    document.getElementById('s_tva').textContent       = pct(s.tva_rate);
    document.getElementById('s_cit').textContent       = pct(s.cit_rate);
    document.getElementById('s_fne').textContent       = pct(s.fne_rate);
    document.getElementById('s_at').textContent        = pct(s.at_rate);
    document.getElementById('s_cnps').textContent      = 'Groupe ' + (s.cnps_group || '—');
    document.getElementById('s_commune').textContent   = fmt(s.commune_monthly || 0);
    document.getElementById('s_filing_day').textContent = 'le ' + (s.filing_day || 15) + ' du mois suivant';
}

// ============================================================
// Wiring
// ============================================================

document.addEventListener('DOMContentLoaded', () => {
    loadDashboard();
    const ysel = document.getElementById('tax-year');
    if (ysel) ysel.addEventListener('change', () => {
        // Reload whatever tab is active.
        const active = document.querySelector('.tab-content.active');
        if (!active) return;
        const name = active.id.replace(/^content-/, '');
        switchTab(name);
    });
    // Escape closes the modal.
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && document.getElementById('tax-monthly-modal').classList.contains('open')) {
            closeMonthlyModal();
        }
    });
    // Click backdrop closes.
    document.getElementById('tax-monthly-modal').addEventListener('click', e => {
        if (e.target.id === 'tax-monthly-modal') closeMonthlyModal();
    });
});
