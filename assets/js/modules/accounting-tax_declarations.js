/**
 * assets/js/modules/accounting-tax_declarations.js
 * Bureau LPC ERP — extracted from modules/accounting/tax_declarations.php (Sprint 6 D2).
 */

// D2 hoister prelude: populate window.PAGE_DATA from the inert JSON block.
(function(){
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

const API = '/api/v1/tax_controller.php';

function switchTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.getElementById('content-' + name).classList.add('active');
    document.querySelectorAll('.tab-link').forEach(el => {
        el.className = el.className.replace('border-emerald-600 text-emerald-700 font-black','border-transparent text-gray-400 font-bold');
    });
    const btn = document.getElementById('tab-' + name);
    if (btn) btn.className = btn.className.replace('border-transparent text-gray-400 font-bold','border-emerald-600 text-emerald-700 font-black');
    if (name === 'dashboard') loadDashboard();
    if (name === 'monthly')   loadHistory();
    if (name === 'withholding') { loadClients(); }
    if (name === 'settings')  loadSettings();
}

function fmt(n) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    return LPC.fmt.fcfa(n);
}

async function post(action, payload) {
    const r = await fetch(API, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action, ...payload})
    });
    return r.json();
}

async function get(qs) {
    const r = await fetch(API + '?' + qs);
    return r.json();
}

async function loadDashboard() {
    const r = await get('action=dashboard');
    if (r.status !== 'success') return;
    const rows = r.data.upcoming.filter(x => x.net > 0 || x.total_due > 0);
    rows.sort((a,b) => (a.due_date||'').localeCompare(b.due_date||''));
    if (rows.length) {
        document.getElementById('kpi_next_due').textContent = rows[0].kind.toUpperCase() + ' · ' + (rows[0].due_date||'');
        document.getElementById('kpi_next_amount').textContent = fmt(rows.reduce((s,x)=>s+x.net,0));
    }
    const airRow = rows.find(x => x.kind==='air');
    document.getElementById('kpi_air_credit').textContent = airRow ? fmt(airRow.credit) : fmt(0);

    document.getElementById('upcoming-body').innerHTML = rows.map(r => `
        <tr>
            <td class="px-6 py-3 font-bold uppercase text-xs">${r.kind}</td>
            <td class="px-6 py-3">${r.year}-${String(r.month).padStart(2,'0')}</td>
            <td class="px-6 py-3 text-right amt">${fmt(r.total_due)}</td>
            <td class="px-6 py-3 text-right amt text-emerald-700">${fmt(r.credit)}</td>
            <td class="px-6 py-3 text-right amt font-black text-red-600">${fmt(r.net)}</td>
            <td class="px-6 py-3">${r.due_date||''}</td>
            <td class="px-6 py-3 text-right">
                <button onclick="quickCompute('${r.kind}',${r.year},${r.month})" class="text-emerald-700 hover:text-emerald-900 font-bold text-xs uppercase">Calculer & enregistrer</button>
            </td>
        </tr>
    `).join('') || `<tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">Aucune échéance à venir.</td></tr>`;
}

async function quickCompute(kind, year, month) {
    document.getElementById('cf_kind').value = kind;
    document.getElementById('cf_year').value = year;
    document.getElementById('cf_month').value = month;
    switchTab('monthly');
    await computeDeclaration();
}

let lastComputed = null;
async function computeDeclaration() {
    const kind = document.getElementById('cf_kind').value;
    const year = parseInt(document.getElementById('cf_year').value);
    const month = parseInt(document.getElementById('cf_month').value);
    const r = await post('compute', {kind, year, month});
    if (r.status !== 'success') { alert(r.message||'Erreur'); return; }
    lastComputed = { kind, year, month, out: r.data };
    const d = r.data;
    document.getElementById('compute-result').classList.remove('hidden');
    document.getElementById('cr_title').textContent  = ({tva:'TVA',air:'AIR — Acompte IS',irpp_dipe:'DIPE',tsr:'TSR',patente:'Patente'})[kind] || kind;
    document.getElementById('cr_period').textContent = d.period + ' · Échéance ' + (d.due_date||'—');
    document.getElementById('cr-lines').innerHTML = d.lines.map(l => `
        <tr class="${l.is_credit ? 'text-emerald-700' : ''}">
            <td class="px-6 py-2 font-mono text-xs">${l.line_code}</td>
            <td class="px-6 py-2">${l.label}${l.informational?' <span class="text-[10px] text-gray-400">(info)</span>':''}</td>
            <td class="px-6 py-2 text-right amt">${l.is_credit?'−':''}${fmt(l.amount)}</td>
        </tr>`).join('');
    document.getElementById('cr_total').textContent  = fmt(d.total_due);
    document.getElementById('cr_credit').textContent = fmt(d.credit);
    document.getElementById('cr_net').textContent    = fmt(d.net);
}

async function persistDeclaration() {
    if (!lastComputed) return;
    const {kind, year, month} = lastComputed;
    const r = await post('persist', {kind, year, month});
    if (r.status === 'success') { alert('Déclaration enregistrée (id ' + r.declaration_id + ').'); loadHistory(); }
    else alert(r.message||'Erreur');
}

async function loadHistory() {
    const r = await get('action=list');
    if (r.status !== 'success') return;
    const kindLabel = k => ({tva:'TVA',air:'AIR',irpp_dipe:'DIPE',tsr:'TSR',patente:'Patente'})[k]||k;
    document.getElementById('history-body').innerHTML = r.data.map(x => `
        <tr>
            <td class="px-6 py-3 font-bold uppercase text-xs">${kindLabel(x.kind)}</td>
            <td class="px-6 py-3">${x.period_year}-${String(x.period_month).padStart(2,'0')}</td>
            <td class="px-6 py-3 text-right amt">${fmt(x.total_due)}</td>
            <td class="px-6 py-3 text-right amt font-black">${fmt(x.net_to_pay)}</td>
            <td class="px-6 py-3"><span class="badge-status st-${x.status}">${x.status}</span></td>
            <td class="px-6 py-3 text-right">
                ${x.status==='ready' ? `<button onclick="markFiled(${x.id})" class="text-blue-700 hover:text-blue-900 font-bold text-xs uppercase">Marquer déclarée</button>` : ''}
                ${x.status==='filed' ? `<button onclick="markPaid(${x.id})" class="text-emerald-700 hover:text-emerald-900 font-bold text-xs uppercase">Marquer payée</button>` : ''}
            </td>
        </tr>`).join('') || `<tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">Aucune déclaration enregistrée.</td></tr>`;
}

async function markFiled(id) {
    const ref = prompt('Référence de récépissé DGI:'); if (ref === null) return;
    const r = await post('mark_filed', {declaration_id: id, reference: ref}); if (r.status==='success') loadHistory();
}
async function markPaid(id) {
    if (!confirm('Confirmer que le paiement a été effectué au Trésor ?')) return;
    const r = await post('mark_paid', {declaration_id: id}); if (r.status==='success') loadHistory();
}

async function loadClients() {
    // Reuse the AR clients endpoint.
    const r = await fetch('/api/v1/invoices_controller.php?action=get_clients').then(r=>r.json());
    if (r.status !== 'success') return;
    const sel = document.getElementById('wc_client');
    sel.innerHTML = '<option value="">— Client —</option>' + r.data.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
}

async function uploadCertificate(e) {
    e.preventDefault();
    const fd = new FormData(e.target); fd.append('action','upload_certificate');
    const r = await fetch(API, {method:'POST', body: fd}).then(r=>r.json());
    if (r.status==='success') { alert('Attestation enregistrée.'); e.target.reset(); }
    else alert(r.message||'Erreur');
}

async function loadSettings() {
    // Basic view of the current settings — read-only for now.
    // (A dedicated write endpoint can be added later.)
    try {
        const r = await fetch('/api/v1/tax_controller.php?action=dashboard').then(r=>r.json());
        // Settings aren't in dashboard payload; do a follow-up server round-trip.
    } catch(e){}
    // Fallback: static display fed by PHP-side dump.
    document.getElementById('s_regime').textContent = window.PAGE_DATA.v1;
    document.getElementById('s_air').textContent    = ((window.PAGE_DATA.v2)*100).toFixed(2)+' %';
    document.getElementById('s_tva').textContent    = ((window.PAGE_DATA.v3)*100).toFixed(2)+' %';
    document.getElementById('s_cit').textContent    = ((window.PAGE_DATA.v4)*100).toFixed(2)+' %';
    document.getElementById('s_cnps').textContent   = window.PAGE_DATA.v7 + ' ' + window.PAGE_DATA.v5;
    document.getElementById('s_niu').textContent    = window.PAGE_DATA.v6;
}

document.addEventListener('DOMContentLoaded', loadDashboard);
