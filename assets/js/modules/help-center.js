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
 *
 * The same response carries `videos`: moments of the training videos where the
 * words are spoken (migration 126), listed under the articles and linking to
 * the player at that second.
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

    // ------------------------------------------------------------ videos
    // A moment of a training video (HelpVideoLibrary::searchMoments()):
    // « Premiers pas · Chapitre 3 · 02:14 — Appuyez sur Accéder au Bureau »,
    // linking to the player at that second. The words of the query are marked
    // in the line, built with text nodes only.
    function fold(s) {
        return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function highlight(el, text, q) {
        var words = fold(q).split(/[^a-z0-9]+/).filter(function (w) { return w.length >= 2; });
        var plain = fold(text);
        // NFD folding keeps one character per character for French text, so
        // offsets in `plain` are offsets in `text`.
        if (plain.length !== text.length || !words.length) { el.textContent = text; return; }
        var marks = [];
        words.forEach(function (w) {
            var re = new RegExp('(^|[^a-z0-9])(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'g'), m;
            while ((m = re.exec(plain))) { marks.push([m.index + m[1].length, m.index + m[1].length + w.length]); }
        });
        marks.sort(function (a, b) { return a[0] - b[0]; });
        var pos = 0;
        marks.forEach(function (r) {
            if (r[0] < pos) return;
            el.appendChild(document.createTextNode(text.slice(pos, r[0])));
            var mk = document.createElement('mark');
            mk.textContent = text.slice(r[0], r[1]);
            el.appendChild(mk);
            pos = r[1];
        });
        el.appendChild(document.createTextNode(text.slice(pos)));
    }

    function momentRow(m, q) {
        var a = document.createElement('a');
        a.className = 'lpc-help-row';
        if (!/^\/modules\/help\/video\.php\?/.test(m.url)) return a;
        a.href = m.url + '&lang=' + encodeURIComponent(lang());
        a.setAttribute('aria-label', m.label + ' — ' + m.text);

        var time = document.createElement('span');
        time.className = 'lpc-help-moment-time';
        time.textContent = m.timecode;
        a.appendChild(time);

        var wrap = document.createElement('span');
        wrap.className = 'min-w-0';
        var t = document.createElement('span');
        t.className = 'lpc-help-row-title';
        t.textContent = m.label;
        wrap.appendChild(t);
        var s = document.createElement('span');
        s.className = 'lpc-help-moment-text block';
        s.appendChild(document.createTextNode('— '));
        var line = document.createElement('span');
        highlight(line, m.text, q);
        s.appendChild(line);
        wrap.appendChild(s);
        a.appendChild(wrap);

        var chev = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        chev.setAttribute('class', 'lpc-help-row-chev');
        chev.setAttribute('width', '18'); chev.setAttribute('height', '18');
        chev.setAttribute('viewBox', '0 0 24 24'); chev.setAttribute('fill', 'currentColor');
        chev.setAttribute('aria-hidden', 'true');
        var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        p.setAttribute('d', 'M8 5.14v13.72a1 1 0 001.5.86l11-6.86a1 1 0 000-1.72l-11-6.86A1 1 0 008 5.14z');
        chev.appendChild(p);
        a.appendChild(chev);
        return a;
    }

    function heading(text) {
        var h = document.createElement('p');
        h.className = 'lpc-help-section-title';
        h.style.marginTop = '1.25rem';
        h.textContent = text;
        return h;
    }

    function render(q, list, videos) {
        videos = videos || [];
        if (browse) browse.hidden = true;
        results.hidden = false;
        resultsList.textContent = '';

        if (!list.length && !videos.length) {
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

        var n = list.length + videos.length;
        resultsTitle.textContent = n + (isEn()
            ? (n > 1 ? ' results' : ' result')
            : (n > 1 ? ' résultats' : ' résultat'));
        if (list.length && videos.length) {
            resultsList.appendChild(heading(isEn() ? 'Articles' : 'Articles'));
        }
        list.forEach(function (r) { resultsList.appendChild(row(r)); });
        if (videos.length) {
            resultsList.appendChild(heading(isEn() ? 'In the training videos' : 'Dans les vidéos de formation'));
            videos.forEach(function (m) { resultsList.appendChild(momentRow(m, q)); });
        }
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
            render(q, (j && j.results) || [], (j && j.videos) || []);
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
