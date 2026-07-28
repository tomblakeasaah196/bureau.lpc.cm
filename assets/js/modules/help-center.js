/**
 * assets/js/modules/help-center.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — search box on modules/help/index.php.
 *
 * Debounced live search against api/v1/help_controller.php?action=search. While
 * a query is active the category grid is hidden and results take its place;
 * clearing the box puts the grid back. No page reload, no history entries — the
 * centre is a place to look something up, not to navigate through.
 *
 * Results are built with createElement/textContent, never innerHTML: the server
 * returns article titles and summaries as plain strings and they stay strings.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 220;
    var MIN_CHARS   = 2;

    var input, results, resultsList, resultsTitle, browse;
    var timer = null;
    var seq = 0;          // guards against a slow early response landing last

    function lang() {
        try { return (window.LPC && window.LPC.lang) || document.documentElement.lang || 'fr'; }
        catch (e) { return 'fr'; }
    }
    function isEn() { return lang() === 'en'; }

    function showBrowse() {
        results.hidden = true;
        if (browse) browse.hidden = false;
        resultsList.textContent = '';
    }

    function row(r) {
        var a = document.createElement('a');
        a.className = 'lpc-help-row';
        a.href = r.url + '&lang=' + encodeURIComponent(lang());

        var wrap = document.createElement('span');
        wrap.className = 'min-w-0';

        var t = document.createElement('span');
        t.className = 'lpc-help-row-title';
        t.textContent = r.title;
        wrap.appendChild(t);

        if (r.summary) {
            var s = document.createElement('span');
            s.className = 'lpc-help-row-sum block';
            s.textContent = r.summary;
            wrap.appendChild(s);
        }
        a.appendChild(wrap);

        var cat = document.createElement('span');
        cat.className = 'lpc-help-badge lpc-help-badge--off';
        cat.style.marginLeft = 'auto';
        cat.textContent = r.category || '';
        a.appendChild(cat);

        return a;
    }

    function render(q, list) {
        if (browse) browse.hidden = true;
        results.hidden = false;
        resultsList.textContent = '';

        if (!list.length) {
            resultsTitle.textContent = isEn()
                ? 'No results for “' + q + '”'
                : 'Aucun résultat pour « ' + q + ' »';
            var p = document.createElement('div');
            p.className = 'lpc-help-empty';
            p.textContent = isEn()
                ? 'Try a different word, or browse by module below.'
                : 'Essayez un autre mot, ou parcourez par module.';
            resultsList.appendChild(p);
            if (browse) browse.hidden = false;   // give them somewhere to go
            return;
        }

        resultsTitle.textContent = list.length + (isEn()
            ? (list.length > 1 ? ' results' : ' result')
            : (list.length > 1 ? ' résultats' : ' résultat'));
        list.forEach(function (r) { resultsList.appendChild(row(r)); });
    }

    function run(q) {
        var mine = ++seq;
        fetch('/api/v1/help_controller.php?action=search&q=' + encodeURIComponent(q) +
              '&lang=' + encodeURIComponent(lang()) + '&limit=20', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (mine !== seq) return;                 // a newer query won
            render(q, (j && j.results) || []);
        })
        .catch(function () { if (mine === seq) render(q, []); });
    }

    function init() {
        input        = document.getElementById('help-search');
        results      = document.getElementById('help-results');
        resultsList  = document.getElementById('help-results-list');
        resultsTitle = document.getElementById('help-results-title');
        browse       = document.getElementById('help-browse');
        if (!input || !results || !resultsList) return;

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < MIN_CHARS) { seq++; showBrowse(); return; }
            timer = setTimeout(function () { run(q); }, DEBOUNCE_MS);
        });

        // Escape clears rather than closing anything — there is nothing to close.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { input.value = ''; seq++; showBrowse(); }
        });

        // ?q= deep link, so a search can be shared.
        try {
            var q0 = new URL(window.location.href).searchParams.get('q');
            if (q0 && q0.length >= MIN_CHARS) { input.value = q0; run(q0); }
        } catch (e) { /* ignore */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
