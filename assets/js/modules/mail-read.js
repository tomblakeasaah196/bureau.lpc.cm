/**
 * assets/js/modules/mail-read.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, the Mail page (modules/mail/index.php; PR 2).
 *
 * The page is drawn by PHP first; this script then swaps the list and the
 * reader with HTML pieces from api/v1/mail/read.php (already escaped there), so
 * folders, conversations, searches and actions don't reload the page. Changing
 * mailbox does (a plain link), so the mounts always have the right mailbox.
 *
 * Browser events (docs/MAIL.md §6.3), CustomEvents on document:
 *   raises   mail:compose        {mode, accountId, messageId?, to?}   Nouveau message, Répondre…, a draft, mailto:
 *            mail:thread-opened  {accountId, threadId, shared, folderRole}
 *            mail:thread-changed {threadId, source: 'read'}            after its own actions
 *   follows  mail:sent           {accountId, threadId?}                list and conversation again (newest open)
 *            mail:thread-changed {threadId}                            (from PR 4 / PR 5) row and reader again
 *            mail:refresh        {accountId?}                          counts and list again
 *
 * A sender's HTML is shown in a sandboxed frame without scripts (srcdoc, made
 * on the server); this script only sizes the frame to its content and sends
 * its mailto: links to the composer. Keyboard shortcuts stay quiet while you
 * type in a field or while a dialog (the composer: aria-modal) is open.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var DATA = {};
    try { DATA = JSON.parse(document.getElementById('mail-page-data').textContent || '{}'); } catch (e) { DATA = {}; }
    if (!DATA.accountId) return;

    var API = DATA.api;
    var state = {
        folder: DATA.folder || { id: 0, key: 'inbox', role: 'inbox', label: '' },
        q: DATA.q || '',
        filter: DATA.filter || 'all',
        page: DATA.page || 1,
        thread: DATA.thread || null,      // {id, latest, latestDraft, flagged, open, count, subject}
        liveUid: null,                     // an older message open in the reader
        liveImages: false,
        images: [],                        // message ids whose pictures the person chose to show
        stamp: DATA.stamp,
        undo: null,
        busy: 0
    };

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
    var el = {
        panes: $('[data-panes]'),
        list: $('[data-list]'),
        head: $('[data-reader-head]'),
        mounts: $('[data-reader-mounts]'),
        messages: $('[data-reader-messages]'),
        empty: $('[data-reader-empty]'),
        reader: $('[data-reader]'),
        search: $('#mr-search'),
        searchFolder: $('[data-search-folder]'),
        clear: $('[data-action="clear-search"]'),
        summary: $('[data-mount-wrap="thread_summary"]'),
        snackbar: $('[data-snackbar]')
    };
    if (!el.panes || !el.list) return;

    // ------------------------------------------------------------------ server

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function query(params) {
        return Object.keys(params).filter(function (k) {
            return params[k] !== null && params[k] !== undefined && params[k] !== '';
        }).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
    }

    function api(method, params) {
        var base = { box: DATA.accountId, folder: state.folder.key };
        var all = Object.assign(base, params || {});
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        var url = API;
        if (method === 'GET') {
            url += '?' + query(all);
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf();
            opts.body = JSON.stringify(all);
        }
        return fetch(url, opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                if (!res.ok || json.status !== 'success') {
                    var err = new Error(json.message || 'bureau n’a pas pu terminer. Réessayez dans un instant.');
                    err.status = res.status;
                    throw err;
                }
                return json;
            });
        }, function () {
            throw new Error('Pas de connexion avec bureau. Vérifiez le réseau, puis réessayez.');
        });
    }

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    }

    function fail(err) {
        say(err && err.message ? err.message : 'bureau n’a pas pu terminer. Réessayez dans un instant.', 'error');
    }

    // ------------------------------------------------------------------ messages to the person

    var snackTimer = null;
    function say(text, kind, undo) {
        if (!el.snackbar || !text) return;
        clearTimeout(snackTimer);
        state.undo = undo || null;
        el.snackbar.classList.toggle('is-error', kind === 'error');
        el.snackbar.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        $('[data-snackbar-text]', el.snackbar).textContent = text;
        $('[data-snackbar-undo]', el.snackbar).hidden = !state.undo;
        el.snackbar.hidden = false;
        snackTimer = setTimeout(hideSnack, kind === 'error' ? 9000 : (state.undo ? 8000 : 5000));
    }

    function hideSnack() {
        if (el.snackbar) el.snackbar.hidden = true;
        state.undo = null;
    }

    if (el.snackbar) {
        el.snackbar.addEventListener('mouseenter', function () { clearTimeout(snackTimer); });
        el.snackbar.addEventListener('mouseleave', function () { snackTimer = setTimeout(hideSnack, 4000); });
    }

    // ------------------------------------------------------------------ the address bar

    function url() {
        return '?' + query({
            box: DATA.accountId,
            folder: state.folder.key,
            thread: state.thread ? state.thread.id : null,
            q: state.q,
            show: state.filter !== 'all' ? state.filter : null,
            page: state.page > 1 ? state.page : null
        });
    }

    function remember(push) {
        var next = url();
        if (next === location.search) return;
        history[push ? 'pushState' : 'replaceState']({ mail: true }, '', next);
    }

    // ------------------------------------------------------------------ counts

    function setCount(node, n) {
        if (!node) return;
        node.hidden = !(n > 0);
        if (node.firstChild && node.firstChild.nodeType === 3) {
            node.firstChild.nodeValue = String(n);
        } else {
            node.insertBefore(document.createTextNode(String(n)), node.firstChild);
        }
    }

    var ICONS = { inbox: 'inbox', drafts: 'file-pen', sent: 'paper-plane', archive: 'box-archive', spam: 'shield-halved', trash: 'trash-can' };

    // A folder appeared or went (Archives is made on the first archive): draw the folder list and picker again
    function syncFolders(folders) {
        var list = $('[data-folder-list]'), pick = $('[data-folder-pick]');
        var have = $$('a[data-folder-id]', list || document).map(function (a) { return a.getAttribute('data-folder-id'); }).join(',');
        var want = folders.map(function (f) { return String(f.id); }).join(',');
        if (!folders.length || have === want) return;
        var pad = '  ';
        if (list) {
            list.textContent = '';
            folders.forEach(function (f) {
                var li = document.createElement('li');
                li.style.setProperty('--mr-depth', f.depth || 0);
                var a = document.createElement('a');
                a.className = 'mr-nav-btn' + (f.id === state.folder.id ? ' is-active' : '');
                a.href = '?box=' + DATA.accountId + '&folder=' + encodeURIComponent(f.key);
                a.setAttribute('data-folder-key', f.key);
                a.setAttribute('data-folder-id', f.id);
                a.setAttribute('data-role', f.role);
                a.setAttribute('data-label', f.label);
                if (f.id === state.folder.id) a.setAttribute('aria-current', 'true');
                var icon = document.createElement('i');
                icon.className = 'fa-solid fa-' + (ICONS[f.role] || 'folder');
                icon.setAttribute('aria-hidden', 'true');
                var name = document.createElement('span');
                name.className = 'mr-nav-name';
                name.textContent = f.label;
                var count = document.createElement('span');
                count.className = 'mr-nav-count';
                count.setAttribute('data-folder-count', f.id);
                count.hidden = true;
                a.appendChild(icon); a.appendChild(name); a.appendChild(count);
                li.appendChild(a);
                list.appendChild(li);
            });
        }
        if (pick) {
            pick.textContent = '';
            folders.forEach(function (f) {
                var o = document.createElement('option');
                o.value = f.key;
                o.setAttribute('data-folder-id', f.id);
                o.setAttribute('data-label', new Array((f.depth || 0) + 1).join(pad) + f.label);
                o.textContent = o.getAttribute('data-label');
                o.selected = f.id === state.folder.id;
                pick.appendChild(o);
            });
        }
    }

    function applyCounts(c) {
        if (!c) return;
        syncFolders(c.folders || []);
        (c.folders || []).forEach(function (f) {
            var n = f.role === 'drafts' ? f.total : f.unread;
            setCount($('[data-folder-count="' + f.id + '"]'), n);
            var opt = $('[data-folder-pick] option[data-folder-id="' + f.id + '"]');
            if (opt) opt.textContent = opt.getAttribute('data-label') + (n > 0 ? ' (' + n + ')' : '');
        });
        Object.keys(c.boxes || {}).forEach(function (id) {
            setCount($('[data-box-count="' + id + '"]'), c.boxes[id]);
        });
        // The sidebar's Courrier badge (PR 1): [data-lpc-mail-unread] inside [data-lpc-mail-link]
        $$('[data-lpc-mail-link] [data-lpc-mail-unread]').forEach(function (badge) {
            var n = Number(c.nav) || 0;
            var shown = badge.firstElementChild, spoken = shown ? shown.nextElementSibling : null;
            if (shown) shown.textContent = n > 99 ? '99+' : String(n); else badge.textContent = String(n);
            if (spoken) spoken.textContent = n + ' non lu' + (n > 1 ? 's' : '');
            badge.hidden = !(n > 0);
            var link = badge.closest('[data-lpc-mail-link]');
            if (link && link.title) link.title = link.title.replace(/\s*\(\d+\)$/, '') + (n > 0 ? ' (' + n + ')' : '');
        });
        if (c.stamp) state.stamp = c.stamp;
    }

    // ------------------------------------------------------------------ the list

    var listSeq = 0;
    function loadList(opts) {
        opts = opts || {};
        var seq = ++listSeq;
        if (!opts.quiet) el.list.setAttribute('aria-busy', 'true');
        return api('GET', {
            action: 'list', q: state.q, show: state.filter, page: state.page,
            open: state.thread ? state.thread.id : null
        }).then(function (json) {
            if (seq !== listSeq) return;
            var keepLive = opts.keepLive && $('[data-live]', el.list) ? $('[data-live]', el.list).innerHTML : '';
            el.list.innerHTML = json.data.html;
            state.page = json.data.page;
            if (keepLive) {
                $('[data-live]', el.list).innerHTML = keepLive;
                var foot = $('[data-list-foot]', el.list);
                if (foot) foot.hidden = true;
            }
            if (opts.top) el.list.scrollTop = 0;
            markActiveRow();
        }).catch(function (err) {
            if (seq === listSeq && !opts.quiet) fail(err);
        }).then(function () {
            if (seq === listSeq) el.list.setAttribute('aria-busy', 'false');
        });
    }

    function markActiveRow() {
        $$('[data-thread]', el.list).forEach(function (row) {
            var on = !!state.thread && Number(row.getAttribute('data-thread')) === state.thread.id;
            row.classList.toggle('is-active', on);
            if (on) row.setAttribute('aria-current', 'true'); else row.removeAttribute('aria-current');
        });
        $$('[data-live-uid]', el.list).forEach(function (row) {
            var on = state.liveUid !== null && Number(row.getAttribute('data-live-uid')) === state.liveUid;
            row.classList.toggle('is-active', on);
            if (on) row.setAttribute('aria-current', 'true'); else row.removeAttribute('aria-current');
        });
    }

    function selectFolder(key, push) {
        var link = $('[data-folder-key="' + cssEscape(key) + '"]');
        var opt = $('[data-folder-pick] option[value="' + cssEscape(key) + '"]');
        if (!link && !opt) return Promise.resolve();
        var id = Number((link || opt).getAttribute('data-folder-id'));
        state.folder = {
            id: id, key: key,
            role: link ? link.getAttribute('data-role') : (id === state.folder.id ? state.folder.role : 'other'),
            label: link ? link.getAttribute('data-label') : (opt.getAttribute('data-label') || '').trim()
        };
        state.q = '';
        state.page = 1;
        if (el.search) el.search.value = '';
        if (el.clear) el.clear.hidden = true;
        if (el.searchFolder) el.searchFolder.value = key;
        $$('[data-folder-key]').forEach(function (a) {
            var on = a.getAttribute('data-folder-key') === key;
            a.classList.toggle('is-active', on);
            if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        });
        var pick = $('[data-folder-pick]');
        if (pick) pick.value = key;
        if (el.summary) el.summary.hidden = state.folder.role === 'spam';
        closeReader(false);
        remember(push !== false);
        return loadList({ top: true });
    }

    function cssEscape(s) {
        return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&');
    }

    // ------------------------------------------------------------------ the reader

    function showReader(on) {
        el.panes.classList.toggle('has-reader', on);
        if (el.empty) el.empty.hidden = on;
    }

    // One pane at a time (lpc-mail-read.css: the panes' container under 720px)
    var shell = $('.mr-shell');
    function phone() {
        return !!shell && shell.clientWidth < 720;
    }

    var threadSeq = 0;
    function openThread(id, opts) {
        opts = opts || {};
        var seq = ++threadSeq;
        var sameThread = state.thread && state.thread.id === id;
        state.liveUid = null;
        if (!sameThread) state.images = [];
        var body = { action: 'thread', thread: id, q: state.q, images: state.images };
        if (opts.open) body.open = opts.open;
        if (opts.latest) body.latest = 1;
        if (opts.quiet) body.quiet = 1;
        if (!opts.quiet) el.reader.setAttribute('aria-busy', 'true');
        return api('POST', body).then(function (json) {
            if (seq !== threadSeq) return;
            renderThread(json.data, opts);
            if (json.data.counts) applyCounts(json.data.counts);
            var row = $('[data-thread="' + id + '"]', el.list);
            if (row) row.classList.remove('is-unread');
            if (!opts.quiet) remember(!opts.replace);
        }).catch(function (err) {
            if (seq !== threadSeq) return;
            if (err.status === 404 && opts.quiet) {
                closeReader(true);
                return;
            }
            fail(err);
        }).then(function () {
            if (seq === threadSeq) el.reader.removeAttribute('aria-busy');
        });
    }

    function renderThread(data, opts) {
        var scroll = opts.quiet ? el.reader.scrollTop : 0;
        state.thread = data.thread;
        el.head.innerHTML = data.head;
        el.messages.innerHTML = data.messages;
        if (el.mounts) el.mounts.hidden = false;
        if (el.summary) el.summary.hidden = state.folder.role === 'spam';
        showReader(true);
        wireFrames(el.messages);
        markActiveRow();
        el.reader.scrollTop = scroll;
        if (!opts.quiet) {
            emit('mail:thread-opened', { accountId: DATA.accountId, threadId: state.thread.id, shared: !!DATA.shared, folderRole: state.folder.role });
            if (phone()) {
                window.scrollTo(0, 0);
                var h = $('.mr-subject', el.head);
                if (h) h.focus({ preventScroll: true });
            }
        }
    }

    function closeReader(push) {
        var had = state.thread ? state.thread.id : null;
        state.thread = null;
        state.liveUid = null;
        state.images = [];
        el.head.innerHTML = '';
        el.messages.innerHTML = '';
        if (el.mounts) el.mounts.hidden = true;
        showReader(false);
        markActiveRow();
        if (push !== undefined) remember(push);
        if (had && phone()) {
            var row = $('[data-thread="' + had + '"]', el.list);
            if (row) { row.scrollIntoView({ block: 'center' }); row.focus({ preventScroll: true }); }
        }
    }

    function openIds() {
        return $$('.mr-msg.is-open[data-message]', el.messages).map(function (a) { return Number(a.getAttribute('data-message')); });
    }

    function reloadThread(opts) {
        if (!state.thread) return Promise.resolve();
        return openThread(state.thread.id, Object.assign({ open: openIds(), replace: true }, opts || {}));
    }

    function replaceMessage(article, html) {
        var box = document.createElement('div');
        box.innerHTML = html;
        var next = box.firstElementChild;
        if (!next) return;
        article.parentNode.replaceChild(next, article);
        wireFrames(next);
    }

    function messageRequest(article, action, extra) {
        var id = Number(article.getAttribute('data-message'));
        article.setAttribute('aria-busy', 'true');
        return api('POST', Object.assign({ action: action, message: id, thread: state.thread ? state.thread.id : null }, extra || {})).then(function (json) {
            replaceMessage(article, json.data.html);
            if (json.data.counts) applyCounts(json.data.counts);
            if (json.message) say(json.message);
        }).catch(function (err) {
            article.removeAttribute('aria-busy');
            fail(err);
        });
    }

    function toggleMessage(article) {
        var head = $('.mr-msg-head', article);
        if (article.getAttribute('data-loaded') === '1') {
            var open = !article.classList.contains('is-open');
            article.classList.toggle('is-open', open);
            if (head) head.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) wireFrames(article);
            return;
        }
        var id = Number(article.getAttribute('data-message'));
        messageRequest(article, 'message', { images: state.images.indexOf(id) !== -1 ? 1 : 0 }).then(function () {
            var row = state.thread && $('[data-thread="' + state.thread.id + '"]', el.list);
            if (row && !$('.mr-msg.is-unread', el.messages)) row.classList.remove('is-unread');
        });
    }

    // ------------------------------------------------------------------ the frames (a sender's HTML)

    function fit(frame) {
        try {
            var doc = frame.contentDocument;
            if (!doc || !doc.body) return;
            // The body, not the document: a document is never shorter than its frame, so it could only grow
            var pad = parseFloat(getComputedStyle(frame).paddingTop || 0) + parseFloat(getComputedStyle(frame).paddingBottom || 0);
            var h = Math.max(doc.body.scrollHeight, Math.ceil(doc.body.getBoundingClientRect().height));
            frame.style.height = Math.max(40, h + pad) + 'px';
        } catch (e) { /* not ready yet */ }
    }

    function wireFrame(frame) {
        var doc;
        try { doc = frame.contentDocument; } catch (e) { return; }
        if (!doc || !doc.body || doc.body.getAttribute('data-wired')) return;
        doc.body.setAttribute('data-wired', '1');
        fit(frame);
        if (window.ResizeObserver) new ResizeObserver(function () { fit(frame); }).observe(doc.body);
        doc.addEventListener('toggle', function () { fit(frame); }, true);
        Array.prototype.forEach.call(doc.images, function (img) { img.addEventListener('load', function () { fit(frame); }); });
        doc.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href^="mailto:"]') : null;
            if (!a || !DATA.canSend) return;
            e.preventDefault();
            composeTo(a.getAttribute('href'));
        });
    }

    function wireFrames(root) {
        $$('iframe.mr-frame', root).forEach(function (frame) {
            frame.addEventListener('load', function () { wireFrame(frame); });
            if (frame.contentDocument && frame.contentDocument.readyState === 'complete') wireFrame(frame);
        });
    }

    // ------------------------------------------------------------------ composing (PR 3's composer)

    function compose(mode, messageId, to) {
        var detail = { mode: mode, accountId: DATA.accountId };
        if (messageId) detail.messageId = Number(messageId);
        if (to) detail.to = to;
        emit('mail:compose', detail);
    }

    function composeTo(href) {
        var to = '';
        try { to = decodeURIComponent(String(href).slice(7).split('?')[0]); } catch (e) { to = String(href).slice(7).split('?')[0]; }
        compose('new', null, to);
    }

    // ------------------------------------------------------------------ actions on the open conversation

    var confirmDlg = $('#mr-confirm');
    function confirmThen(title, text, keep, go, run) {
        if (!confirmDlg || typeof confirmDlg.showModal !== 'function') {
            if (window.confirm(title + '\n\n' + text)) run();
            return;
        }
        $('[data-confirm-title]', confirmDlg).textContent = title;
        $('[data-confirm-text]', confirmDlg).textContent = text;
        $('[data-confirm-keep]', confirmDlg).textContent = keep;
        $('[data-confirm-go]', confirmDlg).textContent = go;
        confirmDlg.onclose = function () {
            if (confirmDlg.returnValue === 'go') run();
            confirmDlg.returnValue = '';
        };
        $('[data-confirm-go]', confirmDlg).value = 'go';
        confirmDlg.showModal();
        $('[data-confirm-keep]', confirmDlg).focus();
    }

    function act(action) {
        if (!state.thread) return;
        if (action === 'delete') {
            var where = state.folder.role === 'spam' ? 'du dossier Indésirables' : 'de la Corbeille';
            var subject = state.thread.subject || '(sans objet)';
            confirmThen('Supprimer définitivement cette conversation ?',
                '« ' + subject.slice(0, 80) + ' » est supprimée ' + where + ' sur le serveur de messagerie. C’est définitif.',
                'La garder', 'Supprimer définitivement', function () { run('delete'); });
            return;
        }
        run(action);

        function run(what) {
            var id = state.thread.id;
            api('POST', { action: 'act', thread: id, do: what, q: state.q }).then(function (json) {
                applyCounts(json.data.counts);
                say(json.message, 'success', json.data.undo);
                emit('mail:thread-changed', { threadId: id, source: 'read' });
                if (json.data.left || what === 'unread') {
                    closeReader(true);
                } else {
                    reloadThread({ quiet: true });
                }
                loadList({ quiet: true });
            }).catch(fail);
        }
    }

    function undo() {
        var u = state.undo;
        hideSnack();
        if (!u) return;
        api('POST', { action: 'undo', ids: u.ids, to: u.folder }).then(function (json) {
            applyCounts(json.data.counts);
            say(json.message);
            loadList({ quiet: true });
        }).catch(fail);
    }

    function emptyFolder() {
        var trash = state.folder.role === 'trash';
        confirmThen(trash ? 'Vider la Corbeille ?' : 'Vider les indésirables ?',
            'Tous les messages ' + (trash ? 'de la Corbeille' : 'du dossier Indésirables') + ' sur le serveur de messagerie sont supprimés définitivement, y compris ceux de plus de 12 mois. C’est définitif.',
            'Les garder', trash ? 'Vider la Corbeille' : 'Vider les indésirables', function () {
                api('POST', { action: 'empty' }).then(function (json) {
                    applyCounts(json.data.counts);
                    say(json.message);
                    closeReader(true);
                    loadList({ top: true });
                }).catch(fail);
            });
    }

    var refreshing = false;
    function refresh(button) {
        if (refreshing) return;
        refreshing = true;
        if (button) { button.classList.add('is-busy'); button.disabled = true; }
        api('POST', { action: 'refresh' }).then(function (json) {
            applyCounts(json.data.counts);
            say(json.message, json.data.skipped ? 'error' : 'success');
            loadList({ quiet: true, keepLive: true });
            if (state.thread) reloadThread({ quiet: true });
        }).catch(fail).then(function () {
            refreshing = false;
            if (button) { button.classList.remove('is-busy'); button.disabled = false; }
        });
    }

    // ------------------------------------------------------------------ past bureau's 12 months (read live)

    function liveBox() { return $('[data-live]', el.list); }

    function older(before, button) {
        if (button) { button.disabled = true; button.classList.add('is-busy'); }
        var box = liveBox();
        api('GET', { action: 'older', before: before || null }).then(function (json) {
            box = liveBox();
            if (!box) return;
            if (json.data.append) {
                var tmp = document.createElement('div');
                tmp.innerHTML = json.data.html;
                var rows = $('.mr-rows', box);
                $$('.mr-rows > li', tmp).forEach(function (li) { if (rows) rows.appendChild(li); });
                var oldFoot = $('.mr-list-foot', box);
                if (oldFoot) oldFoot.remove();
                var newFoot = $('.mr-list-foot', tmp);
                if (newFoot) box.appendChild(newFoot);
            } else {
                box.innerHTML = json.data.html;
            }
            var foot = $('[data-list-foot]', el.list);
            if (foot) foot.hidden = true;
        }).catch(function (err) {
            if (button) { button.disabled = false; button.classList.remove('is-busy'); }
            fail(err);
        });
    }

    function serverSearch(button) {
        if (!state.q) return;
        if (button) { button.disabled = true; button.innerHTML = button.innerHTML.replace('Chercher dans le courrier plus ancien sur le serveur', 'Recherche sur le serveur…'); }
        api('GET', { action: 'server_search', q: state.q }).then(function (json) {
            var box = liveBox();
            if (box) box.innerHTML = json.data.html;
            var foot = $('[data-list-foot]', el.list);
            if (foot) foot.hidden = true;
        }).catch(function (err) {
            if (button) button.disabled = false;
            fail(err);
        });
    }

    function closeLive() {
        var box = liveBox();
        if (box) box.innerHTML = '';
        var foot = $('[data-list-foot]', el.list);
        if (foot) foot.hidden = false;
        if (state.liveUid !== null) closeReader(false);
    }

    function openLive(uid, images, trust) {
        var seq = ++threadSeq;
        state.thread = null;
        state.liveUid = uid;
        state.liveImages = !!images;
        el.reader.setAttribute('aria-busy', 'true');
        api('POST', { action: trust ? 'live_trust' : 'live', uid: uid, images: images ? 1 : 0 }).then(function (json) {
            if (seq !== threadSeq) return;
            el.head.innerHTML = json.data.html;
            el.messages.innerHTML = '';
            if (el.mounts) el.mounts.hidden = true;
            showReader(true);
            wireFrames(el.head);
            markActiveRow();
            el.reader.scrollTop = 0;
            if (json.message) say(json.message);
            if (phone()) window.scrollTo(0, 0);
        }).catch(fail).then(function () {
            if (seq === threadSeq) el.reader.removeAttribute('aria-busy');
        });
    }

    // ------------------------------------------------------------------ search

    var searchTimer = null;
    function search(now) {
        clearTimeout(searchTimer);
        var run = function () {
            var q = (el.search.value || '').trim();
            if (q === state.q) return;
            state.q = q;
            state.page = 1;
            if (el.clear) el.clear.hidden = q === '';
            closeReader(false);
            remember(false);
            loadList({ top: true });
        };
        if (now) run(); else searchTimer = setTimeout(run, 400);
    }

    if (el.search) {
        el.search.addEventListener('input', function () {
            if (el.clear) el.clear.hidden = el.search.value === '';
            search(false);
        });
        el.search.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                el.search.value = '';
                search(true);
                el.search.blur();
            }
        });
        var form = el.search.form;
        if (form) form.addEventListener('submit', function (e) { e.preventDefault(); search(true); });
    }

    // ------------------------------------------------------------------ clicks

    // Another PR's mount (team bar, summary, Ask, composer) handles its own clicks: never act on them here
    function inMount(t) {
        return !!(t && t.closest && t.closest('[data-mount-wrap]'));
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest || inMount(t)) return;
        var plainClick = e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;

        var c = t.closest('[data-compose]');
        if (c) {
            e.preventDefault();
            compose(c.getAttribute('data-compose'), c.getAttribute('data-message'));
            return;
        }

        var mailto = t.closest('[data-reader] a[href^="mailto:"]');
        if (mailto && DATA.canSend && plainClick) {
            e.preventDefault();
            composeTo(mailto.getAttribute('href'));
            return;
        }

        var row = t.closest('a[data-thread]');
        if (row && plainClick) {
            e.preventDefault();
            openThread(Number(row.getAttribute('data-thread')));
            return;
        }

        var live = t.closest('[data-live-uid]');
        if (live) {
            e.preventDefault();
            openLive(Number(live.getAttribute('data-live-uid')), false);
            return;
        }

        var folder = t.closest('a[data-folder-key]');
        if (folder && plainClick) {
            e.preventDefault();
            selectFolder(folder.getAttribute('data-folder-key'));
            return;
        }

        var page = t.closest('[data-page]');
        if (page && !page.disabled) {
            state.page = Number(page.getAttribute('data-page')) || 1;
            remember(false);
            loadList({ top: true });
            return;
        }

        var filter = t.closest('[data-filter]');
        if (filter) {
            state.filter = filter.getAttribute('data-filter');
            state.page = 1;
            $$('.mr-filter[data-filter]').forEach(function (b) {
                var on = b.getAttribute('data-filter') === state.filter;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            remember(false);
            loadList({ top: true });
            return;
        }

        if (t.closest('[data-snackbar-undo]')) { undo(); return; }
        if (t.closest('[data-snackbar-close]')) { hideSnack(); return; }

        var close = t.closest('dialog [data-close]');
        if (close) { close.closest('dialog').close(); return; }

        var a = t.closest('[data-action]');
        if (!a) return;
        var action = a.getAttribute('data-action');
        var article = a.closest('[data-message]');
        switch (action) {
            case 'toggle': if (article) toggleMessage(article); return;
            case 'images':
                if (article) {
                    var id = Number(article.getAttribute('data-message'));
                    if (state.images.indexOf(id) === -1) state.images.push(id);
                    messageRequest(article, 'message', { images: 1 });
                }
                return;
            case 'trust': if (article) messageRequest(article, 'trust'); return;
            case 'reload-message': if (article) messageRequest(article, 'message', { images: 0 }); return;
            case 'open-all':
                if (state.thread) openThread(state.thread.id, { open: $$('[data-message]', el.messages).map(function (m) { return Number(m.getAttribute('data-message')); }), replace: true });
                return;
            case 'close': closeReader(true); return;
            case 'help': var keys = $('#mr-keys'); if (keys) keys.showModal(); return;
            case 'refresh': refresh(a); return;
            case 'clear-search': if (el.search) { el.search.value = ''; search(true); el.search.focus(); } return;
            case 'empty': emptyFolder(); return;
            case 'older': older(Number(a.getAttribute('data-before')) || null, a); return;
            case 'server-search': serverSearch(a); return;
            case 'close-live': closeLive(); return;
            case 'live-images': if (state.liveUid !== null) openLive(state.liveUid, true); return;
            case 'live-trust': if (state.liveUid !== null) openLive(state.liveUid, true, true); return;
            case 'archive': case 'spam': case 'trash': case 'inbox': case 'read': case 'unread':
            case 'flag': case 'unflag': case 'delete':
                act(action);
                return;
        }
    });

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.matches || inMount(t)) return;
        if (t.matches('[data-move]') && t.value) {
            var to = t.value;
            t.value = '';
            act('move:' + to);
        } else if (t.matches('[data-folder-pick]')) {
            selectFolder(t.value);
        } else if (t.matches('[data-box-pick]')) {
            location.href = '?box=' + encodeURIComponent(t.value);
        }
    });

    // ------------------------------------------------------------------ keyboard

    function typing(t) {
        return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
    }

    function dialogOpen() {
        if ($('dialog[open]')) return true;
        return $$('[aria-modal="true"]').some(function (d) { return d.offsetParent !== null || d.getClientRects().length > 0; });
    }

    function step(by) {
        var rows = $$('a[data-thread]', el.list);
        if (!rows.length) return;
        var at = rows.findIndex(function (r) { return r.getAttribute('aria-current') === 'true'; });
        var next = rows[Math.min(rows.length - 1, Math.max(0, at === -1 ? 0 : at + by))];
        next.focus();
        next.scrollIntoView({ block: 'nearest' });
        openThread(Number(next.getAttribute('data-thread')));
    }

    document.addEventListener('keydown', function (e) {
        // AltGr (French keyboards: # is AltGr+3) reports Ctrl+Alt on Windows: it is not a shortcut modifier here
        var altGr = e.getModifierState && e.getModifierState('AltGraph');
        if ((!altGr && (e.ctrlKey || e.metaKey || e.altKey)) || e.defaultPrevented || typing(e.target) || dialogOpen()) return;
        var thread = !!state.thread, live = state.liveUid !== null;
        var organise = !!DATA.organise, send = !!DATA.canSend, role = state.folder.role;
        var run = function (fn) { e.preventDefault(); fn(); };
        switch (e.key) {
            case 'j': return run(function () { step(1); });
            case 'k': return run(function () { step(-1); });
            case '/': return el.search && run(function () { el.search.focus(); el.search.select(); });
            case '?': return run(function () { var k = $('#mr-keys'); if (k) k.showModal(); });
            case 'c': return send && run(function () { compose('new'); });
            case 'u': case 'Escape': return (thread || live) && run(function () { closeReader(true); });
            case 'U': return thread && run(function () { act('unread'); });
            case 'e': return thread && organise && ['archive', 'trash', 'spam'].indexOf(role) === -1 && run(function () { act('archive'); });
            case '!': return thread && organise && role !== 'spam' && run(function () { act('spam'); });
            case '#': return thread && organise && run(function () { act(role === 'trash' || role === 'spam' ? 'delete' : 'trash'); });
            case 's': return thread && organise && run(function () { act(state.thread.flagged ? 'unflag' : 'flag'); });
            case 'r': return thread && send && run(function () { compose(state.thread.latestDraft ? 'draft' : 'reply', state.thread.latest); });
            case 'a': return thread && send && !state.thread.latestDraft && run(function () { compose('reply_all', state.thread.latest); });
            case 'f': return thread && send && !state.thread.latestDraft && run(function () { compose('forward', state.thread.latest); });
        }
    });

    // ------------------------------------------------------------------ the other parts (§6.3)

    document.addEventListener('mail:sent', function (e) {
        var d = e.detail || {};
        if (d.accountId && Number(d.accountId) !== DATA.accountId) return;
        loadList({ quiet: true, keepLive: true });
        if (state.thread && (!d.threadId || Number(d.threadId) === state.thread.id)) reloadThread({ quiet: true, latest: true });
        pollNow();
    });

    document.addEventListener('mail:thread-changed', function (e) {
        var d = e.detail || {};
        // our own actions refresh already; 'ai' (PR 5) means only the summary card changed
        if (d.source === 'read' || d.source === 'ai') return;
        loadList({ quiet: true, keepLive: true });
        if (state.thread && (!d.threadId || Number(d.threadId) === state.thread.id)) reloadThread({ quiet: true });
    });

    document.addEventListener('mail:refresh', function (e) {
        var d = e.detail || {};
        if (d.accountId && Number(d.accountId) !== DATA.accountId) return;
        pollNow(true);
    });

    // ------------------------------------------------------------------ new mail (every minute while the page is in view)

    var polling = false;
    function pollNow(force) {
        if (polling) return;
        polling = true;
        api('GET', { action: 'counts' }).then(function (json) {
            var changed = json.data.stamp !== state.stamp;
            applyCounts(json.data);
            if (changed || force) {
                loadList({ quiet: true, keepLive: true });
                if (state.thread) reloadThread({ quiet: true });
            }
        }).catch(function () { /* the next minute will try again */ }).then(function () { polling = false; });
    }

    setInterval(function () {
        if (document.visibilityState === 'visible') pollNow(false);
    }, Math.max(30, Number(DATA.poll) || 60) * 1000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') pollNow(false);
    });

    // ------------------------------------------------------------------ back and forward

    window.addEventListener('popstate', function () {
        var p = new URLSearchParams(location.search);
        if (Number(p.get('box') || DATA.accountId) !== DATA.accountId) { location.reload(); return; }
        var key = p.get('folder') || 'inbox';
        var q = p.get('q') || '';
        var thread = Number(p.get('thread') || 0);
        state.filter = p.get('show') || 'all';
        state.page = Number(p.get('page') || 1);
        var after = function () {
            if (thread && (!state.thread || state.thread.id !== thread)) openThread(thread, { replace: true });
            else if (!thread && (state.thread || state.liveUid !== null)) closeReader();
        };
        if (key !== state.folder.key) {
            selectFolder(key, false).then(after);
            return;
        }
        if (q !== state.q) {
            state.q = q;
            if (el.search) el.search.value = q;
            if (el.clear) el.clear.hidden = q === '';
        }
        loadList({ quiet: true }).then(after);
    });

    // ------------------------------------------------------------------ start

    wireFrames(document);
    // A conversation opened by the link (the bell, ?thread=): tell the mounts once every page script is listening
    function start() {
        if (!state.thread) return;
        history.replaceState({ mail: true }, '', url());
        emit('mail:thread-opened', { accountId: DATA.accountId, threadId: state.thread.id, shared: !!DATA.shared, folderRole: state.folder.role });
        if (phone()) window.scrollTo(0, 0);
    }
    if (document.readyState === 'complete') start(); else document.addEventListener('DOMContentLoaded', start);
})();
