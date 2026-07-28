/**
 * assets/js/lpc-help.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · help drawer controller.
 *
 * Opens the slide-over from:
 *   · any element carrying  data-lpc-help="<article-slug>"   (the toolbar "?")
 *   · the topbar "?" when it carries data-lpc-help-home
 *   · ?help=<slug> in the URL, so an article is deep-linkable into context
 *   · window.LPC.help.open('<slug>')
 *
 * The body HTML comes from api/v1/help_controller.php, which renders the
 * article server-side through lpc_help_markdown(). This file therefore assigns
 * innerHTML exactly once, to a string the server produced from escaped input —
 * it never builds HTML out of anything a user typed here.
 *
 * The drawer writes ?help=<slug> into the URL with replaceState so a reader can
 * copy the address bar and send a colleague straight to the right explanation
 * on the right page. Closing removes it again.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var root, panel, body, titleEl, eyebrowEl, closeBtn, fullLink;
    var S = {}, LANG = 'fr';
    var lastFocus = null;
    var cache = {};          // slug → rendered payload; help text doesn't change mid-session
    var currentSlug = null;

    // ------------------------------------------------------------------ data
    function loadConfig() {
        var el = document.getElementById('lpc-help-data');
        if (!el) return false;
        try {
            var d = JSON.parse(el.textContent || '{}');
            S = d.strings || {};
            LANG = d.lang || 'fr';
            return true;
        } catch (e) { return false; }
    }

    // ------------------------------------------------------------------ view
    function setBusy(t) {
        titleEl.textContent = t || S.loading || 'Loading…';
        body.textContent = '';
        for (var i = 0; i < 3; i++) {
            var sk = document.createElement('div');
            sk.className = 'lpc-help-skeleton';
            body.appendChild(sk);
        }
    }

    function setError(msg) {
        titleEl.textContent = S.eyebrow || 'Help';
        body.textContent = '';
        var p = document.createElement('p');
        p.className = 'lpc-help-empty';
        p.textContent = msg;
        body.appendChild(p);
    }

    function renderArticle(a) {
        titleEl.textContent = a.title;
        eyebrowEl.textContent = (a.category && a.category.title) || S.eyebrow || '';
        body.textContent = '';

        if (a.fallback) {
            var note = document.createElement('div');
            note.className = 'lpc-help-fallback';
            note.textContent = S.fallback || '';
            body.appendChild(note);
        }

        var prose = document.createElement('div');
        prose.className = 'lpc-help-prose';
        // Server-rendered from escaped Markdown — see lpc_help_markdown().
        prose.innerHTML = a.body_html;
        body.appendChild(prose);

        if (a.see_also && a.see_also.length) {
            var h = document.createElement('p');
            h.className = 'lpc-help-section-title';
            h.textContent = S.seealso || 'See also';
            body.appendChild(h);

            var list = document.createElement('div');
            list.className = 'lpc-help-list';
            a.see_also.forEach(function (s) {
                var link = document.createElement('a');
                link.className = 'lpc-help-row';
                link.href = '#';
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    load(s.slug);
                    body.scrollTop = 0;
                });
                var span = document.createElement('span');
                span.className = 'lpc-help-row-title';
                span.textContent = s.title;
                link.appendChild(span);
                list.appendChild(link);
            });
            body.appendChild(list);
        }

        fullLink.href = '/modules/help/article.php?slug=' + encodeURIComponent(a.slug) + '&lang=' + LANG;
        body.scrollTop = 0;
    }

    // ------------------------------------------------------------------ fetch
    function load(slug) {
        currentSlug = slug;
        syncUrl(slug);

        if (cache[slug]) { renderArticle(cache[slug]); return; }
        setBusy();

        fetch('/api/v1/help_controller.php?action=article&slug=' +
              encodeURIComponent(slug) + '&lang=' + encodeURIComponent(LANG), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            // 404 and 403 both come back as "not available" from the server —
            // it deliberately does not distinguish them, so neither do we.
            if (!res.ok || res.j.status !== 'success' || !res.j.article) {
                setError(S.notfound || 'Not available');
                return;
            }
            cache[slug] = res.j.article;
            renderArticle(res.j.article);
        })
        .catch(function () { setError(S.error || 'Error'); });
    }

    // ------------------------------------------------------------------ url
    function syncUrl(slug) {
        if (!window.history || !history.replaceState) return;
        try {
            var u = new URL(window.location.href);
            if (slug) { u.searchParams.set('help', slug); }
            else      { u.searchParams.delete('help'); }
            history.replaceState(null, '', u.toString());
        } catch (e) { /* older browser — the drawer still works, just not linkable */ }
    }

    // ------------------------------------------------------------ open/close
    function open(slug) {
        if (!root) return;
        if (root.hidden) {
            lastFocus = document.activeElement;
            root.hidden = false;
            document.documentElement.style.overflow = 'hidden';
        }
        load(slug);
        closeBtn.focus();
    }

    function close() {
        if (!root || root.hidden) return;
        root.hidden = true;
        document.documentElement.style.overflow = '';
        currentSlug = null;
        syncUrl(null);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // Focus trap. The palette gets one from lpc-a11y.js; the drawer is opened
    // from a toolbar button mid-form, so a Tab that escapes into the page
    // behind it would put the caret back in the form the user is reading about.
    function trap(e) {
        if (e.key !== 'Tab' || root.hidden) return;
        var f = panel.querySelectorAll(
            'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
        );
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    // ------------------------------------------------------------------ init
    function init() {
        root      = document.getElementById('lpc-help-drawer');
        if (!root) return;                       // migration 032 hasn't run
        panel     = root.querySelector('.lpc-help-panel');
        body      = document.getElementById('lpc-help-drawer-body');
        titleEl   = document.getElementById('lpc-help-drawer-title');
        eyebrowEl = document.getElementById('lpc-help-drawer-eyebrow');
        closeBtn  = document.getElementById('lpc-help-drawer-close');
        fullLink  = document.getElementById('lpc-help-drawer-full');
        if (!panel || !body || !titleEl || !closeBtn) return;
        if (!loadConfig()) return;

        // Delegated: works for buttons rendered after load (modals, tabs).
        document.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('[data-lpc-help]') : null;
            if (!t) return;
            var slug = t.getAttribute('data-lpc-help');
            if (!slug) return;
            e.preventDefault();
            open(slug);
        });

        closeBtn.addEventListener('click', close);
        root.addEventListener('click', function (e) { if (e.target === root) close(); });

        document.addEventListener('keydown', function (e) {
            if (root.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            trap(e);
        });

        // Deep link: /modules/crm/clients.php?help=creer-un-client
        try {
            var q = new URL(window.location.href).searchParams.get('help');
            if (q) open(q);
        } catch (e) { /* ignore */ }

        window.LPC = window.LPC || {};
        window.LPC.help = { open: open, close: close, current: function () { return currentSlug; } };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
