/**
 * assets/js/lpc-help.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · help drawer controller.
 *
 * Opens the slide-over from:
 *   · any element carrying  data-lpc-help="<article-slug>"   (the toolbar "?")
 *     and/or data-lpc-help-videos='[…]' — the training-video moments that
 *     show the page (lpc_help_link()); the drawer offers « Voir la vidéo »
 *     above the article, or alone when the page has no article
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
    var currentVideos = [];  // training-video moments for this page (data-lpc-help-videos)

    // ---------------------------------------------------------------- videos
    // The "?" button carries the moments where a training video shows this
    // page (lpc_help_page_videos(), already filtered by the videos' gate).
    // Only links to the player page are ever followed.
    function readVideos(el) {
        if (!el) return [];
        try {
            var v = JSON.parse(el.getAttribute('data-lpc-help-videos') || '[]');
            return Array.isArray(v) ? v.filter(function (m) {
                return m && typeof m.url === 'string' && /^\/modules\/help\/video\.php\?/.test(m.url);
            }) : [];
        } catch (e) { return []; }
    }

    function videoCard(list) {
        var first = list[0];
        var card = document.createElement('div');
        card.className = 'lpc-help-vcard';

        if (first.poster_url && /^\/api\/v1\/help_videos_controller\.php\?/.test(first.poster_url)) {
            var thumb = document.createElement('a');
            thumb.className = 'lpc-help-vcard-thumb';
            thumb.href = first.url;
            thumb.setAttribute('aria-hidden', 'true');
            thumb.tabIndex = -1;
            var img = document.createElement('img');
            img.src = first.poster_url;
            img.alt = '';
            img.loading = 'lazy';
            thumb.appendChild(img);
            var play = document.createElement('span');
            play.className = 'lpc-help-vcard-play';
            thumb.appendChild(play);
            card.appendChild(thumb);
        }

        var info = document.createElement('div');
        info.className = 'lpc-help-vcard-info';
        var eb = document.createElement('p');
        eb.className = 'lpc-help-vcard-eyebrow';
        eb.textContent = S.videoEyebrow || 'Video';
        info.appendChild(eb);
        var t = document.createElement('p');
        t.className = 'lpc-help-vcard-title';
        t.textContent = first.title;
        info.appendChild(t);
        var meta = document.createElement('p');
        meta.className = 'lpc-help-vcard-meta';
        meta.textContent = (first.chapter ? (S.chapter || 'Chapitre') + ' ' + first.chapter +
                           (first.chapter_title ? ' · ' + first.chapter_title : '') + ' · ' : '') +
                           (S.videoAt || 'à') + ' ' + first.timecode;
        info.appendChild(meta);
        if (first.text) {
            var q = document.createElement('p');
            q.className = 'lpc-help-vcard-quote';
            q.textContent = '« ' + first.text + ' »';
            info.appendChild(q);
        }
        var go = document.createElement('a');
        go.className = 'lpc-help-vcard-btn';
        go.href = first.url;
        go.textContent = (S.video || 'Voir la vidéo') + ' · ' + first.timecode;
        info.appendChild(go);
        card.appendChild(info);

        var wrap = document.createDocumentFragment();
        wrap.appendChild(card);
        if (list.length > 1) {
            var h = document.createElement('p');
            h.className = 'lpc-help-section-title';
            h.textContent = S.videoMore || '';
            wrap.appendChild(h);
            var more = document.createElement('div');
            more.className = 'lpc-help-list';
            list.slice(1).forEach(function (m) {
                var a = document.createElement('a');
                a.className = 'lpc-help-row';
                a.href = m.url;
                var s = document.createElement('span');
                s.className = 'lpc-help-row-title';
                s.textContent = m.title + ' · ' + m.timecode;
                a.appendChild(s);
                more.appendChild(a);
            });
            wrap.appendChild(more);
        }
        return wrap;
    }

    function prependVideos() {
        if (!currentVideos.length) return;
        body.insertBefore(videoCard(currentVideos), body.firstChild);
    }

    function renderVideosOnly() {
        titleEl.textContent = S.pageTitle || S.eyebrow || '';
        eyebrowEl.textContent = S.eyebrow || '';
        body.textContent = '';
        prependVideos();
        fullLink.href = currentVideos[0].url;
        fullLink.textContent = (S.video || 'Voir la vidéo') + ' →';
        body.scrollTop = 0;
    }

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

        prependVideos();
        fullLink.href = '/modules/help/article.php?slug=' + encodeURIComponent(a.slug) + '&lang=' + LANG;
        fullLink.textContent = (S.full || '') + ' →';
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
    function open(slug, videos) {
        if (!root) return;
        if (root.hidden) {
            lastFocus = document.activeElement;
            root.hidden = false;
            document.documentElement.style.overflow = 'hidden';
        }
        // Without an explicit list (?help= deep link, LPC.help.open()), use the
        // page's own "?" button's videos, if it has any.
        currentVideos = Array.isArray(videos) ? videos
            : readVideos(document.querySelector('.lpc-help-btn[data-lpc-help-videos]'));
        if (slug) {
            load(slug);
        } else if (currentVideos.length) {
            currentSlug = null;
            renderVideosOnly();
        }
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
            var t = e.target.closest ? e.target.closest('[data-lpc-help], [data-lpc-help-videos]') : null;
            if (!t) return;
            var slug = t.getAttribute('data-lpc-help') || '';
            var videos = readVideos(t);
            if (!slug && !videos.length) return;
            e.preventDefault();
            open(slug, videos);
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
