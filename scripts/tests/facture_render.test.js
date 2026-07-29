/**
 * Smoke test for documents-facture.js injectData().
 *
 * No jsdom in the sandbox, so this stands up a minimal DOM whose element ids are
 * scraped out of public/documents/facture.php — meaning the test can only pass
 * if the JS and the template actually agree on every id.
 *
 * Two fixtures, because they exercise opposite branches:
 *   A. exonerated water sale, no withholding  -> optional rows must stay hidden,
 *      and the exemption basis must be printed instead of a bare "0 %".
 *   B. 19,25 % sale with accises + précompte + AIR to a withholding agent
 *      -> every optional row appears and net-to-transfer < TTC.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const ROOT = process.argv[2];
const tpl = fs.readFileSync(path.join(ROOT, 'public/documents/facture.php'), 'utf8');
const js = fs.readFileSync(path.join(ROOT, 'assets/js/modules/documents-facture.js'), 'utf8');

const ids = [...tpl.matchAll(/\bid="([^"]+)"/g)].map(m => m[1]);

function makeEl(id) {
    return {
        id,
        innerText: '',
        innerHTML: '',
        className: '',
        _classes: new Set(),
        classList: {
            add(c) { this._o._classes.add(c); },
            remove(c) { this._o._classes.delete(c); },
            toggle(c, on) { on ? this._o._classes.add(c) : this._o._classes.delete(c); },
        },
        setAttribute(k, v) { this['_attr_' + k] = v; },
        getAttribute(k) { return this['_attr_' + k]; },
    };
}

const store = {};
for (const id of ids) {
    const el = makeEl(id);
    el.classList._o = el;
    if (/\bid="[^"]*"[^>]*class="[^"]*\bhidden\b/.test(tpl)) { /* noop */ }
    store[id] = el;
}
// seed the `hidden` class where the template declares it
for (const m of tpl.matchAll(/<[^>]*\bid="([^"]+)"[^>]*>/g)) {
    const tag = m[0], id = m[1];
    const cls = /class="([^"]*)"/.exec(tag);
    if (cls && /\bhidden\b/.test(cls[1])) store[id]._classes.add('hidden');
}

const sandbox = {
    console,
    document: {
        getElementById: id => store[id] || null,
        querySelectorAll: () => [],
        fonts: { ready: Promise.resolve() },
        createElement: () => makeEl('tmp'),
        body: { appendChild() {}, removeChild() {} },
    },
    window: { location: { search: '?token=abc', href: 'https://x/facture.php?token=abc' } },
    Intl,
    URLSearchParams,
    LPC: {
        fmt: {
            fcfa: n => new Intl.NumberFormat('fr-FR').format(Math.round(Number(n) || 0)) + ' FCFA',
            int: n => new Intl.NumberFormat('fr-FR').format(Math.round(Number(n) || 0)),
        },
        html: (s, ...v) => s.reduce((a, p, i) => a + p + (v[i - 1] !== undefined ? '' : ''), ''),
        toast: () => {},
        modal: { alert: () => {} },
    },
    fetch: () => Promise.reject(new Error('not used')),
    requestAnimationFrame: cb => cb(),
    setTimeout,
    URL: { createObjectURL: () => 'blob:', revokeObjectURL: () => {} },
};
sandbox.LPC.html = (strings, ...values) =>
    strings.reduce((out, s, i) => out + s + (i < values.length ? String(values[i] ?? '') : ''), '');
sandbox.window.onload = null;
vm.createContext(sandbox);
vm.runInContext(js + '\n;globalThis.__injectData = injectData; globalThis.__setApi = d => { apiData = d; };', sandbox);

const base = {
    company: {
        name: 'La Petite Cour ETS',
        address_lines: ['Entrée Cie de Gendarmerie de Ndogbong', 'B.P. 5120', 'Douala Littoral', 'Cameroun'],
        contact: 'Tél. +237 696 291 800 · info@lpc.cm',
        legal_mentions: 'RCCM RC/DLA/2019/A/A/687 · NIU M1219800000L · Capital social 5 000 000 FCFA · CDI Douala-Bonanjo',
        fiscal_regime_label: 'Régime du Réel',
        bank: { 'Mobile Money': '696 291 800', Banque: 'UBA Cameroun', IBAN: 'CM21 1003 3052 0123 4567 8901' },
        footer: 'La Petite Cour ETS · RCCM RC/DLA/2019/A/A/687 · NIU M1219800000L',
    },
    client: { name: 'BASE CAMEROON LTD', address: '82, Rue Dikoume Bell, Bali', phone: '+237 656 17 36 68', email: null, niu: null, rccm: null, is_b2b: true },
    stamp: { created_by: 'Timothée M.', role: 'admin', timestamp: '29/07/2026 08:25', hash: 'A817-C82C-C204-2E95' },
    items: [{ description: '20L Opur', quantity: 50, unit_price: 3000, total_price: 150000 }],
};

const A = {
    ...base,
    invoice: {
        reference: 'FAC-2607-6341', date: '2026-07-29', due_date: '2026-08-28',
        subtotal: 150000, excise_rate: 0, excise_amount: 0,
        tva_rate: 0, tva_amount: 0,
        tva_exemption: 'Exonéré de TVA — régime applicable au produit facturé (art. 128 CGI).',
        precompte_rate: 0, precompte_amount: 0, air_rate: 0, air_amount: 0,
        total_amount: 150000, net_payable: 150000, status: 'unpaid',
        paid_amount: 0, balance: 150000, notes: null,
        amount_in_words: 'Cent cinquante mille francs CFA',
    },
};

const B = {
    ...base,
    client: { ...base.client, niu: 'M0419700000P', rccm: 'RC/DLA/2014/B/1122' },
    invoice: {
        reference: 'FAC-2607-6342', date: '2026-07-29', due_date: '2026-08-28',
        subtotal: 1000000, excise_rate: 25, excise_amount: 250000,
        tva_rate: 19.25, tva_amount: 240625,
        tva_exemption: '',
        precompte_rate: 5, precompte_amount: 50000,
        air_rate: 2.2, air_amount: 22000,
        total_amount: 1490625, net_payable: 1418625, status: 'partial',
        paid_amount: 500000, balance: 990625, notes: 'Paiement à 30 jours.',
        amount_in_words: 'Un million quatre cent quatre-vingt-dix mille six cent vingt-cinq francs CFA',
    },
};

function run(name, data, expectations) {
    for (const id of Object.keys(store)) {
        store[id].innerText = '';
        store[id]._classes = new Set();
        store[id].classList._o = store[id];
    }
    for (const m of tpl.matchAll(/<[^>]*\bid="([^"]+)"[^>]*>/g)) {
        const cls = /class="([^"]*)"/.exec(m[0]);
        if (cls && /\bhidden\b/.test(cls[1])) store[m[1]]._classes.add('hidden');
    }
    sandbox.__setApi(data);
    sandbox.__injectData();

    console.log(`\n─── ${name} ───`);
    let failed = 0;
    // Intl fr-FR groups with U+202F (narrow no-break space), not U+0020.
    // That is correct output; normalise so the assertions stay readable.
    const norm = s => String(s).replace(/[   ]/g, ' ');
    for (const [what, actual, expected] of expectations(store)) {
        const ok = norm(actual) === norm(expected);
        if (!ok) failed++;
        console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${what}: ${JSON.stringify(actual)}${ok ? '' : ` (expected ${JSON.stringify(expected)})`}`);
    }
    return failed;
}

const hidden = id => store[id]._classes.has('hidden');
const txt = id => store[id].innerText;

let failures = 0;

failures += run('A · exonerated water sale, no withholding', A, () => [
    ['invoice number is populated (was blank in the html2canvas PDF)', txt('dyn_ref'), 'FAC-2607-6341'],
    ['issue date', txt('dyn_date'), '29/07/2026'],
    ['due date', txt('dyn_due_date'), '28/08/2026'],
    ['company letterhead name', txt('dyn_co_name'), 'La Petite Cour ETS'],
    ['legal mentions carry RCCM + NIU + capital + régime',
        /RCCM .*NIU .*Capital social .*Régime/.test(txt('dyn_co_legal')), 'true'],
    ['TVA rate rendered fr-FR', txt('dyn_tva_rate'), '0'],
    ['exemption basis is shown instead of a bare 0 %', hidden('row_tva_exemption'), 'false'],
    ['exemption text', /art\. 128 CGI/.test(txt('dyn_tva_exemption')), 'true'],
    ['accises row hidden', hidden('row_excise'), 'true'],
    ['withholding block hidden', hidden('withholding_block'), 'true'],
    ['missing client NIU is flagged', hidden('dyn_client_niu_warning'), 'false'],
    ['client NIU placeholder', txt('dyn_client_niu'), '—'],
    ['grand total', txt('dyn_grandtotal'), '150 000 FCFA'],
    ['legal footer populated', txt('dyn_legal_footer').length > 0, 'true'],
]);

failures += run('B · 19,25 % + accises + précompte + AIR', B, () => [
    ['accises row visible', hidden('row_excise'), 'false'],
    ['accises rate fr-FR', txt('dyn_excise_rate'), '25'],
    ['TVA rate uses a comma', txt('dyn_tva_rate'), '19,25'],
    ['no exemption line when TVA applies', hidden('row_tva_exemption'), 'true'],
    ['withholding block visible', hidden('withholding_block'), 'false'],
    ['précompte row visible', hidden('row_precompte'), 'false'],
    ['AIR row visible', hidden('row_air'), 'false'],
    ['AIR rate fr-FR', txt('dyn_air_rate'), '2,2'],
    ['précompte shown as a deduction', txt('dyn_precompte_amount'), '- 50 000 FCFA'],
    ['TTC', txt('dyn_grandtotal'), '1 490 625 FCFA'],
    ['net transferable is TTC minus the withholdings', txt('dyn_net_payable'), '1 418 625 FCFA'],
    ['client NIU printed', txt('dyn_client_niu'), 'M0419700000P'],
    ['no NIU warning when present', hidden('dyn_client_niu_warning'), 'true'],
    ['balance after part payment', txt('dyn_balance'), '990 625 FCFA'],
]);

console.log(failures === 0 ? '\nAll assertions passed.' : `\n${failures} assertion(s) FAILED.`);
process.exit(failures === 0 ? 0 : 1);
