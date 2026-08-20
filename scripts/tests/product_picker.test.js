/**
 * scripts/tests/product_picker.test.js
 * -----------------------------------------------------------------------------
 * Smoke test for assets/js/lpc-product-picker.js.
 *
 * No jsdom in the sandbox, so — same approach as facture_render.test.js — this
 * stands up a minimal DOM. It is deliberately faithful on the handful of APIs
 * the picker actually leans on (dataset, classList, querySelector, custom
 * events, document.body.contains) and absent everywhere else, so a test that
 * passes means the picker used nothing exotic.
 *
 * What is covered, and why each one earns its place:
 *
 *   1. EMBALLAGES ARE EXCLUDED BY DEFAULT. The whole point of the endpoint's
 *      exclusion rule. If this regresses, consignes become sellable again and
 *      the quote is wrong in a way nobody notices until reconciliation.
 *   2. THE TOGGLE REVEALS THEM. Off by default, on by request.
 *   3. ACCENT-INSENSITIVE SEARCH. "petillante" must find "Pétillante" — the
 *      product it matters most for is also the one nobody will type correctly.
 *   4. TOKENS ARE AND-ED AND ORDER-INDEPENDENT. "opur 20" and "20 opur" must
 *      both find "20L Opur".
 *   5. EXACT SKU RANKS FIRST. Typing a full SKU and pressing Enter must not
 *      select a different product that merely contains the string.
 *   6. THE HIDDEN-INPUT CONTRACT. `.prod-id` must survive the upgrade and
 *      `.value` must return the product id — this is what every existing
 *      submit handler in the app reads, and the reason nothing else had to be
 *      rewritten.
 *   7. GROUP HEADERS ARE SKIPPED BY KEYBOARD NAV. They sit in the same array
 *      as the options; arrowing onto one would highlight nothing and Enter
 *      would select nothing.
 *   8. CLEARING EMITS. A cleared line must tell its host to zero the price.
 *   9. SUPPLIER-SCOPED BUY CATALOGUE (migration 115). A purpose=buy picker
 *      mounted with supplierId must ask the endpoint for THAT supplier, must
 *      not share its cached catalogue with another supplier's picker, must
 *      keep the supplier on retry after a failed load, and must still load
 *      when no supplier is given (server falls to products.cost_price).
 *
 * Run:  node scripts/tests/product_picker.test.js .
 * -----------------------------------------------------------------------------
 */
'use strict';

const fs   = require('fs');
const vm   = require('vm');
const path = require('path');

const ROOT = process.argv[2] || path.join(__dirname, '..', '..');
const SRC  = fs.readFileSync(path.join(ROOT, 'assets/js/lpc-product-picker.js'), 'utf8');

// =============================================================================
// Minimal DOM
// =============================================================================
class ClassList {
    constructor(el) { this.el = el; this.set = new Set(); }
    add(...c)      { c.forEach(x => x && this.set.add(x)); this._sync(); }
    remove(...c)   { c.forEach(x => this.set.delete(x)); this._sync(); }
    contains(c)    { return this.set.has(c); }
    toggle(c, on)  { (on === undefined ? !this.set.has(c) : on) ? this.add(c) : this.remove(c); }
    _sync()        { this.el._className = [...this.set].join(' '); }
}

class El {
    constructor(tag) {
        this.tagName  = String(tag).toUpperCase();
        this.children = [];
        this.parentNode = null;
        this.attrs    = {};
        this.dataset  = {};
        this.style    = {};
        this.classList = new ClassList(this);
        this._className = '';
        this._text = '';
        this.hidden = false;
        this.listeners = {};
        this.value = '';
        this.scrollTop = 0;
    }
    get className()  { return this._className; }
    set className(v) {
        this._className = v || '';
        this.classList.set = new Set(String(v || '').split(/\s+/).filter(Boolean));
    }
    get textContent()  { return this.children.length ? this.children.map(c => c.textContent).join('') : this._text; }
    set textContent(v) { this._text = String(v); this.children = []; }
    get innerHTML()    { return this._html || ''; }
    set innerHTML(v)   { this._html = v; if (v === '') this.children = []; }
    get firstElementChild() { return this.children[0] || null; }

    appendChild(n) {
        if (n instanceof Frag) { n.children.forEach(c => this.appendChild(c)); return n; }
        if (n.parentNode) n.parentNode.removeChild(n);
        n.parentNode = this; this.children.push(n); return n;
    }
    insertBefore(n, ref) {
        if (n.parentNode) n.parentNode.removeChild(n);
        const i = this.children.indexOf(ref);
        n.parentNode = this;
        this.children.splice(i < 0 ? this.children.length : i, 0, n);
        return n;
    }
    removeChild(n) {
        const i = this.children.indexOf(n);
        if (i >= 0) { this.children.splice(i, 1); n.parentNode = null; }
        return n;
    }
    remove() { if (this.parentNode) this.parentNode.removeChild(this); }

    setAttribute(k, v)    { this.attrs[k] = String(v); if (k === 'id') this.id = String(v); }
    getAttribute(k)       { return k in this.attrs ? this.attrs[k] : null; }
    removeAttribute(k)    { delete this.attrs[k]; }
    get required()        { return !!this.attrs.required; }

    _all() { return this.children.flatMap(c => [c, ...c._all()]); }
    matches(sel) {
        if (sel.startsWith('.')) return this.classList.contains(sel.slice(1));
        if (sel.startsWith('[') && sel.endsWith(']')) {
            const key = sel.slice(1, -1).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            return key in this.dataset;
        }
        if (sel.includes('[data-idx=')) {
            const m = sel.match(/data-idx="(\d+)"/);
            return this.classList.contains('lpc-pp__opt') && this.dataset.idx === m[1];
        }
        return this.tagName === sel.toUpperCase();
    }
    querySelector(sel)    { return this.querySelectorAll(sel)[0] || null; }
    querySelectorAll(sel) {
        const parts = sel.split(',').map(s => s.trim());
        return this._all().filter(n => parts.some(p => {
            if (p.includes('[data-idx=')) {
                const m = p.match(/data-idx="(\d+)"/);
                return n.classList.contains('lpc-pp__opt') && n.dataset.idx === m[1];
            }
            return n.matches(p);
        }));
    }
    closest(sel) { let n = this; while (n) { if (n.matches && n.matches(sel)) return n; n = n.parentNode; } return null; }
    contains(n)  { return n === this || this._all().includes(n); }

    addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); }
    removeEventListener(t, fn) {
        if (!this.listeners[t]) return;
        this.listeners[t] = this.listeners[t].filter(f => f !== fn);
    }
    dispatchEvent(e) {
        e.target = e.target || this;
        let n = this;
        while (n) { (n.listeners[e.type] || []).forEach(fn => fn.call(n, e)); n = e.bubbles ? n.parentNode : null; }
        return true;
    }
    focus() { doc.activeElement = this; }
    select() {}
    getBoundingClientRect() { return { top: 100, bottom: 130, left: 40, right: 340, width: 300, height: 30 }; }
}

class Frag extends El { constructor() { super('#fragment'); } }

const doc = {
    body: new El('body'),
    readyState: 'complete',
    activeElement: null,
    listeners: {},
    createElement: t => new El(t),
    createDocumentFragment: () => new Frag(),
    addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); },
    removeEventListener(t, fn) {
        if (this.listeners[t]) this.listeners[t] = this.listeners[t].filter(f => f !== fn);
    },
    querySelector(s)    { return doc.body.querySelector(s); },
    querySelectorAll(s) { return doc.body.querySelectorAll(s); },
};

// The catalogue the fake endpoint serves. Mirrors the real Master Data Hub rows
// from the screenshots, including the two empties families.
const CATALOG = [
    mk(701, 'WAT-20L-OP',  '20L Opur',                         '20L',  'Eau', 10, 1850, 412, 'ok',  false, { id: 903, name: 'Emballage 20L avec bouchon', price: 300 }),
    mk(702, 'WAT-10L-OP',  '10L OPUR',                          '10L',  'Eau', 10,  850,  40, 'low', false, { id: 904, name: 'Emballage 10L avec bouchon', price: 100 }),
    mk(703, 'WAT-10L-SM',  '10L Supermont',                     '10L',  'Eau', 10,  900,   0, 'out', false, null),
    mk(704, 'WAT-33CL-002','Supermont La Pétillante Verre 33cl','33cl', 'Eau', 10, 5500, 120, 'ok',  false, null),
    mk(705, 'WAT-33CL-001','Supermont La Source Verre 33cl',    '33cl', 'Eau', 10, 5500,  90, 'ok',  false, null),
    mk(706, 'WAT-0.5L-SM', 'Supermont 0.5L',                    '0.5L', 'Eau', 10, 2200, 300, 'ok',  false, null),
    mk(707, 'Wat-202-2',   'Jus 30L',                           '30L',  'Jus', 20, 3200,  15, 'ok',  false, null),
    mk(903, 'VIDE-20L-C',  'Emballage 20L avec bouchon',        '20L',  'Emballage', 30, 300, 88, 'ok', true, null),
    mk(904, 'VIDE-10L-C',  'Emballage 10L avec bouchon',        '10L',  'Emballage', 30, 100, 55, 'ok', true, null),
];

function mk(id, code, name, format, category, sort, price, stock, state, isEmpty, consigne) {
    return {
        id, code, name, format, category, category_code: category.toLowerCase(),
        category_sort: sort, price, base_price: price, cump: Math.round(price * 0.6),
        unit_of_measure: 'bonbonne', units_per_pack: 1, sold_by: 'unit',
        stock_qty: stock, min_stock_level: 50, stock_state: state,
        is_empty: isEmpty, consigne,
        // Same fold the PHP does: lowercase, accents stripped.
        search: [code, name, format, category, 'bonbonne'].join(' ')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(),
    };
}

// =============================================================================
// Harness
// =============================================================================
const observers = [];
// Every URL the picker fetches, in order — lets the tests assert what the
// catalogue was asked for (purpose, client, supplier) rather than only that
// something loaded.
const fetchCalls = [];
// When true, the next fetch fails with HTTP 500 — used to exercise the
// error state and its Réessayer button.
let failNextFetch = false;
const sandbox = {
    window: {
        addEventListener() {}, removeEventListener() {},
        innerHeight: 900, innerWidth: 1400,
    },
    document: doc,
    console,
    setTimeout, clearTimeout,
    Number, Math, Object, Array, String, Boolean, JSON, Set, Promise, Error, Date,
    CustomEvent: class { constructor(t, o = {}) { this.type = t; this.bubbles = !!o.bubbles; this.detail = o.detail; } preventDefault() {} },
    Event:       class { constructor(t, o = {}) { this.type = t; this.bubbles = !!o.bubbles; } preventDefault() {} },
    MutationObserver: class { constructor(cb) { this.cb = cb; observers.push(this); } observe() {} },
    fetch: (url) => {
        fetchCalls.push(String(url));
        if (failNextFetch) {
            failNextFetch = false;
            return Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) });
        }
        return Promise.resolve({
            ok: true, status: 200,
            json: () => Promise.resolve({ status: 'success', data: CATALOG }),
        });
    },
    encodeURIComponent,
};
sandbox.window.LPC = {};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(SRC, sandbox);
const API = sandbox.window.LPC.productPicker;

// ---- assertions -------------------------------------------------------------
let pass = 0, fail = 0;
function ok(label, cond, extra) {
    if (cond) { pass++; console.log(`  ✓ ${label}`); }
    else { fail++; console.log(`  ✗ ${label}${extra ? '\n      ' + extra : ''}`); }
}
function eq(label, actual, expected) {
    ok(label, JSON.stringify(actual) === JSON.stringify(expected),
       `expected ${JSON.stringify(expected)}\n      got      ${JSON.stringify(actual)}`);
}

/** Options currently rendered, in order, as product names. */
const names = p => p.view.filter(Boolean).map(x => x.name);

// =============================================================================
async function run() {
    console.log('\nlpc-product-picker.js\n');

    // Mount over a <select class="prod-id"> exactly as clients.php does.
    const row = new El('td');
    doc.body.appendChild(row);
    const sel = new El('select');
    sel.className = 'prod-id';
    sel.dataset.lpcProductPicker = 'sell';
    row.appendChild(sel);

    let lastSelect = 'UNSET';
    const picker = API.mount(sel, { purpose: 'sell', onSelect: p => { lastSelect = p; } });
    await new Promise(r => setTimeout(r, 0));      // let the fetch promise settle

    console.log('catalogue + exclusion');
    ok('loaded', picker.loaded);
    picker.filter('');
    ok('1. emballages excluded by default',
       !names(picker).some(n => n.startsWith('Emballage')),
       names(picker).join(' | '));
    eq('   sellable count is 7', names(picker).length, 7);

    picker.emptiesCb.checked = true;
    picker.emptiesCb.dispatchEvent({ type: 'change' });
    ok('2. toggle reveals emballages',
       names(picker).filter(n => n.startsWith('Emballage')).length === 2,
       names(picker).join(' | '));
    picker.emptiesCb.checked = false;
    picker.emptiesCb.dispatchEvent({ type: 'change' });

    console.log('\nsearch');
    picker.filter('petillante');
    eq('3. accent-insensitive: "petillante"', names(picker), ['Supermont La Pétillante Verre 33cl']);

    picker.filter('opur 20');
    eq('4a. tokens AND-ed: "opur 20"', names(picker), ['20L Opur']);
    picker.filter('20 opur');
    eq('4b. order-independent: "20 opur"', names(picker), ['20L Opur']);

    picker.filter('WAT-10L-SM');
    eq('5a. exact SKU ranks first', names(picker)[0], '10L Supermont');
    picker.filter('10l');
    ok('5b. partial still returns the family',
       names(picker).length >= 2 && names(picker).every(n => /10L/i.test(n)),
       names(picker).join(' | '));

    picker.filter('zzzz');
    eq('   no match yields nothing', names(picker), []);

    console.log('\ngrouping + keyboard');
    picker.filter('');
    const headers = picker.view.filter(v => v === null).length;
    eq('   two category groups (Eau, Jus)', headers, 2);
    ok('7. nav skips group headers',
       picker.view[picker._nextSelectable(-1, 1)] !== null &&
       picker.view[picker._nextSelectable(0, 1)]  !== null);

    console.log('\nhidden-input contract');
    const hidden = row.querySelector('.prod-id');
    ok('6a. .prod-id survives the upgrade', !!hidden && hidden.tagName === 'INPUT');
    ok('6b. it is not the original <select>', hidden !== sel);

    let changeFired = 0, detail = null;
    hidden.addEventListener('change', () => changeFired++);
    hidden.addEventListener('lpc:product-change', e => { detail = e.detail; });

    picker.select(CATALOG[0]);
    eq('6c. .value is the product id', hidden.value, '701');
    eq('   change event fired', changeFired, 1);
    eq('   lpc:product-change carries the product', detail && detail.product.code, 'WAT-20L-OP');
    eq('   onSelect got the product', lastSelect && lastSelect.code, 'WAT-20L-OP');
    eq('   input shows the name', picker.input.value, '20L Opur');
    ok('   clear button revealed', picker.clearBtn.hidden === false);

    console.log('\nclearing');
    picker.clear();
    eq('8a. value emptied', hidden.value, '');
    eq('8b. onSelect got null', lastSelect, null);
    eq('   change fired again', changeFired, 2);
    ok('   clear button hidden again', picker.clearBtn.hidden === true);

    console.log('\ntyping over a selection');
    picker.select(CATALOG[0]);
    picker.input.value = '20L Opu';                       // one char deleted
    picker.input.dispatchEvent({ type: 'input', bubbles: false });
    eq('   id dropped while text no longer matches', hidden.value, '');

    console.log('\nsetValue before load (deferred resolve)');
    const sel2 = new El('select');
    sel2.className = 'prod-id';
    doc.body.appendChild(sel2);
    const p2 = API.mount(sel2, {});
    p2.setValue(707);
    eq('   parked in _pending', String(p2.hidden.value), '707');
    await new Promise(r => setTimeout(r, 0));
    eq('   resolved to the product after load', p2.selected && p2.selected.name, 'Jus 30L');

    console.log('\nsupplier-scoped buy catalogue (migration 115)');
    const supSel = new El('select');
    supSel.className = 'po-prod-select';
    doc.body.appendChild(supSel);
    const pSup = API.mount(supSel, { purpose: 'buy', supplierId: 12 });
    await new Promise(r => setTimeout(r, 0));

    ok('buy picker asks for its supplier',
       fetchCalls.some(u => u.includes('purpose=buy') && u.includes('supplier_id=12')),
       'fetches: ' + fetchCalls.join(' | '));

    const supSel2 = new El('select');
    supSel2.className = 'po-prod-select';
    doc.body.appendChild(supSel2);
    const pSup2 = API.mount(supSel2, { purpose: 'buy', supplierId: 13 });
    await new Promise(r => setTimeout(r, 0));

    const for12 = fetchCalls.filter(u => u.includes('supplier_id=12')).length;
    const for13 = fetchCalls.filter(u => u.includes('supplier_id=13')).length;
    ok('a different supplier is a different catalogue (no shared cache)',
       for12 === 1 && for13 === 1,
       `supplier 12 fetched ${for12}x, supplier 13 fetched ${for13}x`);

    // Retry must re-ask with the supplier still attached: fail the next
    // fetch, click the real Réessayer button, and check the retry URL.
    const supSel4 = new El('select');
    supSel4.className = 'po-prod-select';
    doc.body.appendChild(supSel4);
    failNextFetch = true;
    const pSup4 = API.mount(supSel4, { purpose: 'buy', supplierId: 14 });
    await new Promise(r => setTimeout(r, 0));
    const retryBtn = pSup4.list.querySelector('.lpc-pp__retry');
    ok('a failed load renders the retry button', !!retryBtn);
    if (retryBtn) {
        const before = fetchCalls.length;
        retryBtn.dispatchEvent(new sandbox.Event('click', { bubbles: true }));
        await new Promise(r => setTimeout(r, 0));
        ok('retry keeps the supplier scoping',
           fetchCalls.slice(before).some(u => u.includes('supplier_id=14')),
           'fetches: ' + fetchCalls.slice(before).join(' | '));
    }
    pSup4.destroy();

    // A buy picker mounted with NO supplier still loads (falls to cost_price
    // server-side) and must not collide with a supplier-scoped cache.
    const supSel3 = new El('select');
    supSel3.className = 'po-prod-select';
    doc.body.appendChild(supSel3);
    const pSup3 = API.mount(supSel3, { purpose: 'buy' });
    await new Promise(r => setTimeout(r, 0));
    ok('buy without supplier loads too',
       fetchCalls.some(u => u.includes('purpose=buy') && !u.includes('supplier_id')),
       'fetches: ' + fetchCalls.join(' | '));

    pSup.destroy(); pSup2.destroy(); pSup3.destroy();

    console.log('\ngeneric list mode (mountList)');
    // The Prix & Tarifs modal picks a CLIENT with the same component. Clients
    // have no SKU, no stock and no price — the renderer must skip all three
    // rather than print "0 en stock" against a customer.
    const selC = new El('select');
    selC.className = 'client-id';
    doc.body.appendChild(selC);
    const pc = API.mountList(selC, {
        items: [
            { id: 1, name: 'PROMETAL S.A',       subtitle: 'Entreprise' },
            { id: 2, name: 'Hôtel La Falaise',   subtitle: 'Entreprise' },
            { id: 3, name: 'Boulangerie Sévère', subtitle: 'Particulier' },
        ],
        cacheKey: 'test-clients',
    });
    await new Promise(r => setTimeout(r, 0));

    pc.filter('');
    eq('   lists all clients', names(pc).length, 3);
    pc.filter('falaise');
    eq('   filters by name', names(pc), ['Hôtel La Falaise']);
    pc.filter('severe');
    eq('   accent-folded client search', names(pc), ['Boulangerie Sévère']);

    pc.filter('');
    const rowC = pc.list.querySelector('.lpc-pp__opt');
    ok('   no price column for a priceless item',
       !rowC._all().some(n => n.classList.contains('lpc-pp__price')));
    ok('   no stock chip either',
       !rowC._all().some(n => n.classList.contains('lpc-pp__chip')));

    pc.select(pc.view.filter(Boolean)[0]);
    eq('   hidden input carries the client id', pc.hidden.value, '1');

    console.log('\nteardown');
    ok('   popup is on <body> while alive', doc.body.children.includes(p2.pop));
    p2.destroy();
    ok('   popup removed with the field', !doc.body.children.includes(p2.pop));
    p2.destroy();                                        // must be idempotent
    ok('   destroy() is idempotent', true);

    console.log(`\n${pass} passed, ${fail} failed\n`);
    process.exit(fail ? 1 : 0);
}

run().catch(e => { console.error(e); process.exit(1); });
