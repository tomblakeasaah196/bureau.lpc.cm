/**
 * assets/js/lpc-paginator.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · client-side paginator UI.
 *
 * Pairs with includes/classes/Paginator.php. Given a <tbody> and an API URL,
 * fetches page by page, renders rows via a caller-supplied callback, and
 * paints a small "« Prev · N of M · Next »" footer with a per-page selector.
 *
 * USAGE:
 *
 *   const pager = LPC.paginator.attach(document.getElementById('rows'), {
 *       url: '/api/v1/inventory_controller.php?action=read&tab=stock',
 *       renderRow: (row) => LPC.html`<tr>
 *           <td>${row.name}</td>
 *           <td class="text-right">${LPC.fmt.int(row.current_qty)}</td>
 *       </tr>`,
 *       pageSize: 25,
 *       searchInput: document.getElementById('search-stock'),
 *       // Optional: pull the array from a nested response envelope.
 *       // The API returns { status, data: { table: [], page, ... } } for
 *       // legacy `read` actions; use dataPath to point at the array.
 *       dataPath: 'data.table',
 *       // Optional: pull { total, page, per_page, total_pages } from a
 *       // different path. Defaults to the same object as dataPath's parent.
 *       envelopePath: 'data',
 *       // Optional: extra query params sent on every request.
 *       extraParams: { tab: 'stock' },
 *       // Persist page + q in URL hash so back-button works.
 *       hashKey: 'stock',
 *   });
 *
 *   pager.reload();        // force refresh (e.g. after a save)
 *   pager.setPage(3);      // jump to page 3
 *   pager.destroy();       // detach listeners + footer
 *
 * The tbody's parent is used as the anchor for the footer.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.paginator) return;

    const PAGE_SIZES = [10, 25, 50, 100, 200];
    const DEBOUNCE_MS = 300;

    // ------------------------------------------------------------------
    // Small utilities
    // ------------------------------------------------------------------
    function getPath(obj, path) {
        if (!path) return obj;
        const parts = path.split('.');
        let cur = obj;
        for (const p of parts) {
            if (cur === null || cur === undefined) return undefined;
            cur = cur[p];
        }
        return cur;
    }

    function debounce(fn, ms) {
        let t = null;
        return function () {
            const args = arguments, ctx = this;
            if (t) clearTimeout(t);
            t = setTimeout(() => fn.apply(ctx, args), ms);
        };
    }

    function readHashState(key) {
        if (!key) return {};
        // Very small hash parser: #a=1&b=foo
        const raw = String(window.location.hash || '').replace(/^#/, '');
        if (!raw) return {};
        const bag = {};
        raw.split('&').forEach(part => {
            const [k, v] = part.split('=');
            if (!k) return;
            bag[decodeURIComponent(k)] = decodeURIComponent(v || '');
        });
        // Namespace by key.
        const prefix = key + '_';
        const out = {};
        Object.keys(bag).forEach(k => {
            if (k.indexOf(prefix) === 0) out[k.slice(prefix.length)] = bag[k];
        });
        return out;
    }

    function writeHashState(key, state) {
        if (!key) return;
        const raw = String(window.location.hash || '').replace(/^#/, '');
        const bag = {};
        raw.split('&').forEach(part => {
            const [k, v] = part.split('=');
            if (!k) return;
            bag[decodeURIComponent(k)] = decodeURIComponent(v || '');
        });
        // Wipe our namespace, then re-apply.
        const prefix = key + '_';
        Object.keys(bag).forEach(k => { if (k.indexOf(prefix) === 0) delete bag[k]; });
        Object.keys(state).forEach(k => {
            const v = state[k];
            if (v === null || v === undefined || v === '') return;
            bag[prefix + k] = String(v);
        });
        const next = Object.keys(bag).map(k =>
            encodeURIComponent(k) + '=' + encodeURIComponent(bag[k])
        ).join('&');
        // Silent update — don't push a new history entry per keystroke.
        if (('history' in window) && history.replaceState) {
            history.replaceState(null, '', window.location.pathname + window.location.search + (next ? '#' + next : ''));
        } else {
            window.location.hash = next;
        }
    }

    // ------------------------------------------------------------------
    // Footer renderer
    // ------------------------------------------------------------------
    function buildFooter(tbody) {
        // Anchor the footer directly after the containing table (or the tbody's
        // grandparent if no <table> ancestor). Preserve any existing footer of
        // ours by reusing it — the pager is idempotent on reattach.
        const table = tbody.closest('table') || tbody.parentElement;
        const anchor = table ? table.parentElement : tbody.parentElement;
        if (!anchor) return null;

        // Reuse an existing footer already attached under the anchor.
        let footer = anchor.querySelector(':scope > .lpc-pager-footer');
        if (footer) return footer;

        footer = document.createElement('div');
        footer.className = 'lpc-pager-footer flex items-center justify-between gap-3 py-2 px-1 text-xs text-gray-400 border-t border-white/5 mt-1';
        footer.innerHTML = ''
            + '<div class="lpc-pager-info">—</div>'
            + '<div class="flex items-center gap-2">'
            + '  <label class="lpc-pager-size-label opacity-75">Par page&nbsp;'
            + '    <select class="lpc-pager-size bg-transparent border border-white/10 rounded px-1 py-0.5 text-xs">'
            +        PAGE_SIZES.map(n => `<option value="${n}">${n}</option>`).join('')
            + '    </select>'
            + '  </label>'
            + '  <button type="button" class="lpc-pager-prev px-2 py-1 rounded border border-white/10 hover:bg-white/5 disabled:opacity-40 disabled:cursor-not-allowed">« Préc.</button>'
            + '  <span class="lpc-pager-pageof opacity-75">—</span>'
            + '  <button type="button" class="lpc-pager-next px-2 py-1 rounded border border-white/10 hover:bg-white/5 disabled:opacity-40 disabled:cursor-not-allowed">Suiv. »</button>'
            + '</div>';
        // Insert after the anchor's last table/child so it lands under the grid.
        if (table && table.parentElement === anchor) {
            anchor.insertBefore(footer, table.nextSibling);
        } else {
            anchor.appendChild(footer);
        }
        return footer;
    }

    // ------------------------------------------------------------------
    // Core: attach a paginator to a tbody.
    // ------------------------------------------------------------------
    function attach(tbody, opts) {
        if (!tbody) throw new Error('LPC.paginator.attach: missing tbody');
        opts = opts || {};
        const url          = opts.url;
        const renderRow    = typeof opts.renderRow === 'function' ? opts.renderRow : null;
        const dataPath     = opts.dataPath     || 'data';
        const envelopePath = opts.envelopePath || null; // where {page, per_page, total, total_pages} lives
        const extraParams  = opts.extraParams  || {};
        const searchInput  = opts.searchInput  || null;
        const hashKey      = opts.hashKey      || null;
        const emptyMsg     = opts.emptyMsg     || 'Aucun résultat.';
        const onRender     = typeof opts.onRender === 'function' ? opts.onRender : null;
        const colspan      = opts.colspan || null;
        if (!url) throw new Error('LPC.paginator.attach: missing url');
        if (!renderRow) throw new Error('LPC.paginator.attach: missing renderRow(row)');

        // ---- State (rehydrated from URL hash if present) ----
        const hashState = readHashState(hashKey);
        let state = {
            page:     Math.max(1, parseInt(hashState.page || '1', 10) || 1),
            per_page: parseInt(hashState.per || String(opts.pageSize || 25), 10) || 25,
            q:        hashState.q || (searchInput ? searchInput.value : '') || '',
            total_pages: 1,
            total: 0,
        };
        // Keep the user's page-size choice a legal one.
        if (PAGE_SIZES.indexOf(state.per_page) === -1) state.per_page = 25;

        const footer = buildFooter(tbody);
        const info   = footer ? footer.querySelector('.lpc-pager-info') : null;
        const pageOf = footer ? footer.querySelector('.lpc-pager-pageof') : null;
        const prevBtn= footer ? footer.querySelector('.lpc-pager-prev') : null;
        const nextBtn= footer ? footer.querySelector('.lpc-pager-next') : null;
        const sizeSel= footer ? footer.querySelector('.lpc-pager-size') : null;
        if (sizeSel) sizeSel.value = String(state.per_page);
        if (searchInput && state.q) searchInput.value = state.q;

        function persistHash() {
            writeHashState(hashKey, {
                page: state.page > 1 ? state.page : '',
                per:  state.per_page !== 25 ? state.per_page : '',
                q:    state.q || '',
            });
        }

        function renderEmpty(msg) {
            const cols = colspan || (tbody.rows && tbody.rows[0] ? tbody.rows[0].cells.length : 1) || 6;
            tbody.innerHTML = LPC.html`<tr><td colspan="${cols}" class="text-center opacity-60 py-8">${msg}</td></tr>`;
        }

        function renderRows(rows) {
            if (!rows || !rows.length) { renderEmpty(emptyMsg); return; }
            let html = '';
            for (let i = 0; i < rows.length; i++) {
                try { html += renderRow(rows[i], i); }
                catch (e) { console.error('lpc-paginator: renderRow threw', e, rows[i]); }
            }
            tbody.innerHTML = html;
            if (window.LPC && LPC.applyGates) LPC.applyGates(tbody);
            if (onRender) { try { onRender(rows, state); } catch (e) { console.error(e); } }
        }

        function renderFooter() {
            if (!footer) return;
            const start = state.total === 0 ? 0 : ((state.page - 1) * state.per_page) + 1;
            const end   = Math.min(state.total, state.page * state.per_page);
            if (info)   info.textContent   = `${start.toLocaleString('fr-FR')}–${end.toLocaleString('fr-FR')} sur ${state.total.toLocaleString('fr-FR')}`;
            if (pageOf) pageOf.textContent = `${state.page} / ${state.total_pages}`;
            if (prevBtn) prevBtn.disabled  = state.page <= 1;
            if (nextBtn) nextBtn.disabled  = state.page >= state.total_pages;
        }

        function buildUrl() {
            const u = new URL(url, window.location.origin);
            u.searchParams.set('page',     String(state.page));
            u.searchParams.set('per_page', String(state.per_page));
            if (state.q) u.searchParams.set('q', state.q);
            Object.keys(extraParams || {}).forEach(k => {
                const v = extraParams[k];
                if (v === null || v === undefined) return;
                u.searchParams.set(k, String(v));
            });
            return u.toString();
        }

        let inflight = null;
        async function fetchPage() {
            const req = buildUrl();
            renderEmpty('Chargement…');
            if (inflight && typeof inflight.abort === 'function') { try { inflight.abort(); } catch (e) {} }
            const ctrl = ('AbortController' in window) ? new AbortController() : null;
            inflight = ctrl;
            try {
                const res = await fetch(req, {
                    credentials: 'same-origin',
                    signal: ctrl ? ctrl.signal : undefined,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const j = await res.json();
                if (!j || j.status === 'error') throw new Error((j && j.message) || 'Erreur serveur');

                // Extract rows + envelope. Envelope lives on the same node as dataPath.
                const rows = getPath(j, dataPath) || [];
                const env  = envelopePath ? getPath(j, envelopePath) : getPath(j, dataPath.split('.').slice(0, -1).join('.')) || {};
                state.total       = Number(env.total || rows.length) || 0;
                state.total_pages = Math.max(1, Number(env.total_pages || Math.ceil(state.total / state.per_page)) || 1);
                if (state.page > state.total_pages) state.page = state.total_pages;

                renderRows(rows);
                renderFooter();
                persistHash();
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                console.error('lpc-paginator fetch error', e);
                renderEmpty('Erreur de chargement. Réessayez.');
            } finally {
                inflight = null;
            }
        }

        // ---- Wire footer controls ----
        function goto(p) {
            state.page = Math.max(1, Math.min(state.total_pages || 1, p | 0));
            fetchPage();
        }
        if (prevBtn) prevBtn.addEventListener('click', () => goto(state.page - 1));
        if (nextBtn) nextBtn.addEventListener('click', () => goto(state.page + 1));
        if (sizeSel) sizeSel.addEventListener('change', () => {
            state.per_page = parseInt(sizeSel.value, 10) || 25;
            state.page = 1;
            fetchPage();
        });

        // ---- Wire search input (debounced) ----
        let searchHandler = null;
        if (searchInput) {
            searchHandler = debounce(() => {
                const v = searchInput.value.trim();
                if (v === state.q) return;
                state.q = v;
                state.page = 1;
                fetchPage();
            }, DEBOUNCE_MS);
            searchInput.addEventListener('input', searchHandler);
        }

        // ---- Kick off initial fetch ----
        fetchPage();

        return {
            reload:  fetchPage,
            setPage: goto,
            state:   () => Object.assign({}, state),
            setSearch: (q) => { state.q = String(q || ''); state.page = 1; if (searchInput) searchInput.value = state.q; fetchPage(); },
            destroy: () => {
                if (prevBtn)     prevBtn.replaceWith(prevBtn.cloneNode(true));
                if (nextBtn)     nextBtn.replaceWith(nextBtn.cloneNode(true));
                if (sizeSel)     sizeSel.replaceWith(sizeSel.cloneNode(true));
                if (searchInput && searchHandler) searchInput.removeEventListener('input', searchHandler);
                if (footer) footer.remove();
            },
        };
    }

    window.LPC.paginator = { attach };
})();
