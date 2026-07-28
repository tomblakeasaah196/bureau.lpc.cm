/**
 * assets/js/lpc-palette.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — ⌘K / Ctrl+K command palette.
 *
 * Index comes from #lpc-palette-data, built server-side and already filtered by
 * RBAC (see includes/components/command_palette.php). Pages, actions and help
 * article TITLES are all in that payload, so the palette answers instantly.
 *
 * Matching is a small subsequence-with-word-boundary-bonus scorer: typing "gl"
 * finds "Grand Livre", "fact" finds "Facturation & AR". Recent choices are kept
 * in localStorage per browser.
 *
 * SPRINT 7G — the one fetch this file makes
 * -----------------------------------------
 * At 3+ characters it also queries api/v1/help_controller.php?action=search,
 * which searches article BODIES (too large to inline on every page load), and
 * merges the hits under the Help group. Two properties are preserved:
 *
 *   · The endpoint filters by RBAC server-side through lpc_help_search(), so
 *     the rule "the palette can only reach what the sidebar could" still holds.
 *     This file continues to invent no destinations of its own.
 *   · Local results render immediately and are never blocked on the network.
 *     Remote hits are appended when they arrive, and a stale response from an
 *     earlier keystroke is discarded by sequence number.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var RECENT_KEY = 'lpc.palette.recent';
    var RECENT_MAX = 5;

    // Live help search: only past this length, and only after the user pauses.
    var HELP_MIN_CHARS = 3;
    var HELP_DEBOUNCE  = 200;

    var root, input, results, trigger;
    var ITEMS = [];
    var S = {};
    var view = [];          // currently rendered entries
    var cursor = 0;

    var helpTimer = null;
    var helpSeq   = 0;      // discards responses that arrive out of order
    var helpCache = {};     // query → results; help text is static per session

    // ---------------------------------------------------------------- data
    function loadData() {
        var el = document.getElementById('lpc-palette-data');
        if (!el) return false;
        try {
            var d = JSON.parse(el.textContent || '{}');
            ITEMS = Array.isArray(d.items) ? d.items : [];
            S = d.strings || {};
            return ITEMS.length > 0;
        } catch (e) { return false; }
    }

    function recent() {
        try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
        catch (e) { return []; }
    }
    function remember(url) {
        try {
            var list = recent().filter(function (u) { return u !== url; });
            list.unshift(url);
            localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
        } catch (e) { /* private mode — recents just won't persist */ }
    }

    // ------------------------------------------------------------- matching
    function fold(s) {
        // Strip accents so "ecriture" matches "écriture".
        return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function score(hay, needle) {
        var h = fold(hay), n = fold(needle);
        if (!n) return 0;
        if (h.indexOf(n) === 0) return 1000;              // prefix
        var wordStart = h.indexOf(' ' + n);
        if (wordStart > -1) return 900 - wordStart;        // start of a word
        var direct = h.indexOf(n);
        if (direct > -1) return 700 - direct;             // substring

        // subsequence
        var hi = 0, ni = 0, pts = 0, streak = 0;
        while (hi < h.length && ni < n.length) {
            if (h[hi] === n[ni]) {
                streak++;
                pts += 10 + streak * 2 + (hi === 0 || h[hi - 1] === ' ' ? 12 : 0);
                ni++;
            } else { streak = 0; }
            hi++;
        }
        return ni === n.length ? pts : -1;
    }

    function search(q) {
        if (!q) {
            var rec = recent();
            var out = [];
            rec.forEach(function (u) {
                var hit = ITEMS.filter(function (it) { return it.u === u; })[0];
                if (hit) out.push(Object.assign({}, hit, { g: S.recent || 'Recent' }));
            });
            // Fill the rest with the natural index order.
            ITEMS.forEach(function (it) {
                if (out.length >= 40) return;
                if (out.some(function (o) { return o.u === it.u; })) return;
                out.push(it);
            });
            return out;
        }
        return ITEMS
            .map(function (it) {
                var s = Math.max(score(it.l, q), score(it.h || '', q) - 250);
                return { it: it, s: s };
            })
            .filter(function (r) { return r.s > -1; })
            .sort(function (a, b) { return b.s - a.s; })
            .slice(0, 40)
            .map(function (r) { return r.it; });
    }

    // --------------------------------------------------------- live help search
    var HELP_ICON = 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z';

    function lang() {
        try { return (window.LPC && window.LPC.lang) || document.documentElement.lang || 'fr'; }
        catch (e) { return 'fr'; }
    }

    /** Merge remote help hits into a local result list, dropping duplicates. */
    function merge(local, remote) {
        var seen = {};
        local.forEach(function (it) { seen[it.u] = true; });
        var out = local.slice();
        remote.forEach(function (r) {
            var u = r.url + '&lang=' + encodeURIComponent(lang());
            if (seen[u] || seen[r.url]) return;
            out.push({
                g: S.helpGroup || 'Help',
                l: r.title,
                h: r.category || '',
                u: u,
                i: HELP_ICON,
                m: ''
            });
        });

        // Cluster by group, preserving both the order groups first appeared in
        // and the order of items within each. Without this, a help article that
        // scored well locally sits mid-list and a second "Aide" heading appears
        // at the bottom for the remote hits — the same group, printed twice.
        var order = [], buckets = {};
        out.forEach(function (it) {
            if (!buckets[it.g]) { buckets[it.g] = []; order.push(it.g); }
            buckets[it.g].push(it);
        });
        return order.reduce(function (acc, g) { return acc.concat(buckets[g]); }, []);
    }

    /**
     * Body-text search, debounced. Only ever ADDS to what is already on screen:
     * if the network is slow or down, the palette behaves exactly as it did
     * before this existed.
     */
    function queueHelp(q) {
        clearTimeout(helpTimer);
        if (!S.helpEnabled || q.length < HELP_MIN_CHARS) return;

        if (helpCache[q]) { render(merge(search(q), helpCache[q])); return; }

        helpTimer = setTimeout(function () {
            var mine = ++helpSeq;
            fetch('/api/v1/help_controller.php?action=search&q=' + encodeURIComponent(q) +
                  '&lang=' + encodeURIComponent(lang()) + '&limit=6', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (mine !== helpSeq) return;              // a newer keystroke won
                if (input.value.trim() !== q) return;      // the user moved on
                var hits = (j && j.results) || [];
                helpCache[q] = hits;
                if (!hits.length) return;                  // nothing to add — leave the list alone
                render(merge(search(q), hits));
            })
            .catch(function () { /* offline: local results already rendered */ });
        }, HELP_DEBOUNCE);
    }

    // ------------------------------------------------------------- rendering
    function icon(d) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS(ns, 'path');
        p.setAttribute('stroke-linecap', 'round');
        p.setAttribute('stroke-linejoin', 'round');
        p.setAttribute('stroke-width', '1.6');
        p.setAttribute('d', d || '');
        svg.appendChild(p);
        return svg;
    }

    function render(list) {
        view = list;
        cursor = 0;
        results.textContent = '';

        if (!list.length) {
            var empty = document.createElement('p');
            empty.className = 'lpc-palette-empty';
            empty.textContent = S.noResults || 'No results';
            results.appendChild(empty);
            return;
        }

        var lastGroup = null;
        list.forEach(function (it, i) {
            if (it.g !== lastGroup) {
                lastGroup = it.g;
                var h = document.createElement('div');
                h.className = 'lpc-palette-group';
                h.textContent = it.g;
                results.appendChild(h);
            }

            var a = document.createElement('a');
            a.className = 'lpc-palette-item';
            a.href = it.u;
            a.setAttribute('role', 'option');
            a.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
            a.dataset.idx = String(i);

            var ic = document.createElement('span');
            ic.className = 'lpc-palette-icon';
            ic.appendChild(icon(it.i));
            a.appendChild(ic);

            var tx = document.createElement('span');
            tx.className = 'lpc-palette-text';
            var lb = document.createElement('span');
            lb.className = 'lpc-palette-label';
            lb.textContent = it.l;                       // textContent: never innerHTML
            tx.appendChild(lb);
            if (it.h) {
                var hn = document.createElement('span');
                hn.className = 'lpc-palette-hint';
                hn.textContent = it.h;
                tx.appendChild(hn);
            }
            a.appendChild(tx);

            if (it.m) {
                var mt = document.createElement('span');
                mt.className = 'lpc-palette-meta';
                mt.textContent = it.m;
                a.appendChild(mt);
            }

            a.addEventListener('mouseenter', function () { move(i, false); });
            a.addEventListener('click', function () { remember(it.u); });
            results.appendChild(a);
        });
    }

    function move(to, scroll) {
        var nodes = results.querySelectorAll('.lpc-palette-item');
        if (!nodes.length) return;
        if (to < 0) to = nodes.length - 1;
        if (to >= nodes.length) to = 0;
        cursor = to;
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].setAttribute('aria-selected', i === to ? 'true' : 'false');
        }
        if (scroll !== false) {
            nodes[to].scrollIntoView({ block: 'nearest' });
        }
    }

    function commit() {
        var node = results.querySelectorAll('.lpc-palette-item')[cursor];
        if (!node) return;
        remember(node.getAttribute('href'));
        window.location.href = node.getAttribute('href');
    }

    // ---------------------------------------------------------------- open/close
    var lastFocus = null;

    function open() {
        if (!root || !root.hidden) return;
        lastFocus = document.activeElement;
        root.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        input.value = '';
        render(search(''));
        input.focus();
    }

    function close() {
        if (!root || root.hidden) return;
        root.hidden = true;
        document.documentElement.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // ---------------------------------------------------------------- wiring
    function init() {
        root    = document.getElementById('lpc-palette');
        input   = document.getElementById('lpc-palette-input');
        results = document.getElementById('lpc-palette-results');
        trigger = document.getElementById('lpc-search-trigger');
        if (!root || !input || !results) return;
        if (!loadData()) return;

        // Show the right modifier on non-Mac keyboards.
        var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
        var hint = document.querySelector('[data-lpc-kbd-hint]');
        if (hint && !isMac) hint.textContent = 'Ctrl K';

        if (trigger) trigger.addEventListener('click', open);

        document.addEventListener('keydown', function (e) {
            // ⌘K / Ctrl+K opens from anywhere.
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                root.hidden ? open() : close();
                return;
            }
            if (root.hidden) return;

            if (e.key === 'Escape')    { e.preventDefault(); close(); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); move(cursor + 1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); move(cursor - 1); }
            else if (e.key === 'Enter')     { e.preventDefault(); commit(); }
            else if (e.key === 'Tab')       { e.preventDefault(); move(cursor + (e.shiftKey ? -1 : 1)); }
        });

        input.addEventListener('input', function () {
            var q = input.value.trim();
            render(search(q));      // local, synchronous — never wait on the network
            queueHelp(q);           // remote body matches, appended if and when they land
        });

        // Click the scrim (but not the panel) to dismiss.
        root.addEventListener('click', function (e) { if (e.target === root) close(); });

        window.LPC = window.LPC || {};
        window.LPC.palette = { open: open, close: close };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
