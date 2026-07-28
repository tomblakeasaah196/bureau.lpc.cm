/**
 * assets/js/modules/help-admin.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — modules/admin/help_articles.php.
 *
 * Talks to api/v1/help_controller.php. Every write carries the CSRF token from
 * the form's hidden field (Csrf::field()), which the controller requires.
 *
 * The preview renders through the SERVER's lpc_help_markdown(), not a JS
 * Markdown library. Two reasons, and the second is the real one:
 *   · one renderer means the preview cannot disagree with the published page;
 *   · a client-side renderer would be a second, unaudited path from author text
 *     to HTML, and the server's escape-first ordering is the whole reason an
 *     author cannot inject script into every reader's session.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var form, status;

    function csrf() {
        var el = document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    function isEn() {
        try { return (document.documentElement.lang || 'fr') === 'en'; } catch (e) { return false; }
    }

    function say(msg, ok) {
        if (!status) return;
        status.textContent = msg;
        status.style.color = ok ? '#15803D' : '#B91C1C';
        if (ok) { setTimeout(function () { status.textContent = ''; }, 4000); }
    }

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('_csrf', csrf());
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch('/api/v1/help_controller.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
            body: body
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, j: j }; });
        });
    }

    // ------------------------------------------------------------------ list
    function wireList() {
        document.addEventListener('click', function (e) {
            var t = e.target.closest ? e.target.closest('[data-help-toggle]') : null;
            if (t) {
                e.preventDefault();
                post('toggle', { id: t.getAttribute('data-help-toggle') }).then(function (res) {
                    if (!res.ok || res.j.status !== 'success') { return; }
                    var on = res.j.is_published === 1;
                    t.className = 'lpc-help-badge ' + (on ? 'lpc-help-badge--on' : 'lpc-help-badge--off');
                    t.textContent = on ? (isEn() ? 'Published' : 'Publié')
                                       : (isEn() ? 'Draft' : 'Brouillon');
                });
                return;
            }

            var d = e.target.closest ? e.target.closest('[data-help-delete]') : null;
            if (d) {
                e.preventDefault();
                var msg = isEn()
                    ? 'Delete this article? Its French and English text go with it.'
                    : 'Supprimer cet article ? Ses textes français et anglais seront supprimés avec lui.';
                if (!window.confirm(msg)) return;
                post('delete', { id: d.getAttribute('data-help-delete') }).then(function (res) {
                    if (!res.ok || res.j.status !== 'success') return;
                    var row = d.closest('tr');
                    if (row) row.remove();
                });
            }
        });
    }

    // ---------------------------------------------------------------- editor
    /** Fill the form from the server when editing an existing article. */
    function hydrate(id) {
        fetch('/api/v1/help_controller.php?action=load_article&id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || j.status !== 'success') { say(isEn() ? 'Could not load.' : 'Chargement impossible.', false); return; }
            var a = j.article, b = j.bodies || {};

            set('category_id', a.category_id);
            set('slug', a.slug);
            set('required_permission', a.required_permission || '');
            set('kind', a.kind);
            set('page_path', a.page_path || '');
            set('sort_order', a.sort_order);
            var pub = form.querySelector('[name="is_published"]');
            if (pub) pub.checked = String(a.is_published) === '1';

            ['fr', 'en'].forEach(function (L) {
                var d = b[L] || {};
                set('title_' + L,    d.title    || '');
                set('summary_' + L,  d.summary  || '');
                set('body_' + L,     d.body_md  || '');
                set('keywords_' + L, d.keywords || '');
            });
        })
        .catch(function () { say(isEn() ? 'Could not load.' : 'Chargement impossible.', false); });
    }

    function set(name, value) {
        var el = form.querySelector('[name="' + name + '"]');
        if (el) el.value = value;
    }

    function wireEditor() {
        form = document.getElementById('help-article-form');
        if (!form) return;
        status = document.getElementById('help-save-status');

        var id = parseInt(form.getAttribute('data-article-id') || '0', 10);
        if (id > 0) hydrate(id);

        // Slug suggestion — only while creating, and only until the author
        // touches the field. Renaming the slug of a published article would
        // break every link anyone has already shared, so we never auto-change
        // an existing one.
        var slugEl = form.querySelector('[name="slug"]');
        var titleFr = form.querySelector('[name="title_fr"]');
        var slugTouched = false;
        if (slugEl) slugEl.addEventListener('input', function () { slugTouched = true; });
        if (titleFr && slugEl && id === 0) {
            titleFr.addEventListener('input', function () {
                if (slugTouched) return;
                slugEl.value = titleFr.value
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            say(isEn() ? 'Saving…' : 'Enregistrement…', true);

            var data = {};
            new FormData(form).forEach(function (v, k) { if (k !== 'action' && k !== '_csrf') data[k] = v; });
            // An unchecked checkbox is absent from FormData entirely.
            var pub = form.querySelector('[name="is_published"]');
            data.is_published = (pub && pub.checked) ? '1' : '';

            post('save_article', data).then(function (res) {
                if (!res.ok || res.j.status !== 'success') {
                    say(res.j && res.j.message ? res.j.message : (isEn() ? 'Save failed.' : 'Échec.'), false);
                    return;
                }
                say(isEn() ? 'Saved.' : 'Enregistré.', true);
                form.setAttribute('data-article-id', res.j.id);
                set('id', res.j.id);
                // Put the ID in the URL so a refresh reopens what was saved
                // rather than a blank "new article" form.
                try {
                    var u = new URL(window.location.href);
                    u.searchParams.delete('new');
                    u.searchParams.set('edit', res.j.id);
                    history.replaceState(null, '', u.toString());
                } catch (err) { /* older browser */ }
            });
        });

        var prev = document.getElementById('help-preview-btn');
        var box  = document.getElementById('help-preview');
        var out  = document.getElementById('help-preview-body');
        if (prev && box && out) {
            prev.addEventListener('click', function () {
                var body = form.querySelector('[name="body_fr"]');
                post('preview', { body_md: body ? body.value : '' }).then(function (res) {
                    if (!res.ok || res.j.status !== 'success') return;
                    // Server-rendered from escaped input — see lpc_help_markdown().
                    out.innerHTML = res.j.html;
                    box.hidden = false;
                    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
        }
    }

    function init() { wireList(); wireEditor(); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
