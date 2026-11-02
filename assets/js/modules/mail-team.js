/**
 * assets/js/modules/mail-team.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier PR 4: the team bar of a shared mailbox's
 * conversation (modules/mail/mounts/thread_team.php; docs/MAIL.md §6.2, §6.3).
 *
 * Follows the page's browser events:
 *   mail:thread-opened  {threadId, shared}   show the bar, mark "viewing"
 *   mail:composing      {threadId, state}    'replying' / 'stopped' (PR 3)
 *   mail:thread-changed {threadId}           someone else changed it: reload
 *   mail:sent, mail:refresh                  reload the open conversation
 * and raises mail:thread-changed {threadId, source: 'team'} after an
 * assignment or a status change, so PR 2 refreshes the row and header.
 *
 * Presence: a heartbeat every poll_seconds (20) renews "viewing" and brings
 * the team's changes. It pauses while the tab is hidden, the session is
 * locked, or nobody has touched the page for 5 minutes (then the mark lapses
 * within a minute, and an idle page does not keep the session open). Leaving
 * the conversation or the page removes the mark at once.
 *
 * Everything people wrote (notes, names) goes in with textContent only.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-mail-team]');
    if (!root || root.__mtw) return;
    root.__mtw = true;

    var API = root.getAttribute('data-api') || '/api/v1/mail/team.php';
    var IDLE_MS = 5 * 60 * 1000;
    var NOTE_MAX = 5000;

    var current = 0;          // the shared conversation on screen
    var state = null;         // the server's last answer for it
    var panel = '';           // '', 'notes' or 'activity'
    var timer = null;
    var pollMs = 20000;
    var lastInput = Date.now();
    var sent = 0;             // request counter: an older answer never overwrites a newer one
    var applied = 0;
    var drafts = {};          // unsent note per conversation
    var parts = null;         // the bar's pieces, built once per conversation
    var busy = false;         // an action is on its way
    var lastBeat = 0;

    // ------------------------------------------------------------------ helpers

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function toast(message, kind) {
        if (message && window.LPC && window.LPC.toast) {
            (window.LPC.toast[kind || 'success'] || window.LPC.toast)(message);
        }
    }

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    }

    function request(method, body, query, keepalive) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf();
            opts.body = JSON.stringify(body || {});
        }
        if (keepalive) opts.keepalive = true;
        return fetch(API + (query ? '?' + query : ''), opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                if (!res.ok || json.status !== 'success') {
                    var err = new Error(json.message || 'Bureau n’a pas pu terminer. Réessayez dans un instant.');
                    err.field = json.field || null;
                    err.status = res.status;
                    throw err;
                }
                return json;
            });
        }, function () {
            var err = new Error('Pas de connexion avec bureau. Vérifiez le réseau, puis réessayez.');
            err.status = 0;
            throw err;
        });
    }

    /** el('p', {class: 'x', text: 'y'}, [children]) */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') node.textContent = v;
            else if (k === 'class') node.className = v;
            else node.setAttribute(k, v === true ? '' : String(v));
        });
        (children || []).forEach(function (c) { if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return node;
    }

    function avatar(person, extra) {
        return el('span', { class: 'mtw-avatar' + (extra ? ' ' + extra : ''), 'aria-hidden': 'true', text: person.initials || '?' });
    }

    /** "Awa", "Awa et Paul", "Awa, Paul et Marc" */
    function names(list) {
        var n = list.map(function (p) { return p.name; });
        if (n.length < 2) return n.join('');
        return n.slice(0, -1).join(', ') + ' et ' + n[n.length - 1];
    }

    function idle() { return Date.now() - lastInput > IDLE_MS; }
    function locked() { return !!(window.LPC && window.LPC.sessionLock && window.LPC.sessionLock.isLocked && window.LPC.sessionLock.isLocked()); }

    /**
     * Rebuild a piece without losing the keyboard focus inside it: the same control again, else fallback()
     * (a control that went away, e.g. "M’assigner" once it is yours, or a deleted note's button).
     */
    function rebuild(container, build, fallback) {
        var active = document.activeElement;
        var key = null;
        var had = !!(active && container.contains(active));
        if (had) {
            key = active.getAttribute('data-act') ? '[data-act="' + active.getAttribute('data-act') + '"]'
                : active.getAttribute('data-panel') ? '[data-panel="' + active.getAttribute('data-panel') + '"]'
                : active.id ? '#' + active.id : null;
        }
        container.textContent = '';
        build(container);
        if (!had) return;
        var again = (key && container.querySelector(key)) || (fallback && fallback());
        if (again) again.focus();
    }

    // ------------------------------------------------------------------ open, leave, poll

    function open(threadId, shared) {
        threadId = Number(threadId) || 0;
        if (!threadId || shared === false) { close(); return; }
        if (threadId === current) {
            if (Date.now() - lastBeat > 2000) beat(true);   // the page said it again: renew, once
            return;
        }
        close();
        current = threadId;
        panel = '';
        beat(true);
        schedule();
    }

    function close() {
        if (current) {
            saveDraft();
            leave(current);
        }
        current = 0;
        state = null;
        parts = null;
        stop();
        root.hidden = true;
        root.textContent = '';
    }

    function leave(threadId, onUnload) {
        request('POST', { action: 'leave', thread: threadId }, null, !!onUnload).catch(function () { /* the mark lapses by itself */ });
    }

    function stop() { if (timer) { clearInterval(timer); timer = null; } }

    function schedule() {
        stop();
        timer = setInterval(function () { beat(false); }, pollMs);
    }

    /** Renew "viewing" and pick up the team's changes */
    function beat(force) {
        if (!current) return;
        if (!force && (document.hidden || locked() || idle())) return;
        var id = current;
        var n = ++sent;
        lastBeat = Date.now();
        request('POST', { action: 'heartbeat', thread: id, activity: panel === 'activity' }).then(function (json) {
            apply(id, n, json.data);
        }, function (err) {
            if (id !== current) return;
            if (err.status === 403 || err.status === 404) close();   // not a shared conversation this person can read
        });
    }

    function reload() {
        if (!current) return;
        var id = current;
        var n = ++sent;
        request('GET', null, 'thread=' + encodeURIComponent(id) + (panel === 'activity' ? '&activity=1' : '')).then(function (json) {
            apply(id, n, json.data);
        }, function (err) {
            if (id === current && (err.status === 403 || err.status === 404)) close();
        });
    }

    function apply(id, n, data) {
        if (id !== current || n < applied || !data) return;
        applied = n;
        state = data;
        var ms = Number(data.poll_seconds) * 1000;
        if (ms >= 5000 && ms !== pollMs) { pollMs = ms; schedule(); }
        render();
    }

    /** An action: POST, then show the answer; field errors go next to the note box */
    function act(body, opts) {
        opts = opts || {};
        if (!current) return Promise.resolve();
        var id = current;
        body.thread = id;
        body.activity = panel === 'activity';
        var n = ++sent;
        setBusy(true);
        showError('');
        return request('POST', body).then(function (json) {
            apply(id, n, json.data);
            if (json.changed && opts.changed) emit('mail:thread-changed', { threadId: id, source: 'team' });
            if (opts.toast !== false) toast(json.message);
            return json;
        }, function (err) {
            if (err.field === 'body' && parts) {
                noteError(err.message);
            } else {
                showError(err.message);
            }
            if (err.status === 403 || err.status === 404) reload();
            throw err;
        }).finally(function () { setBusy(false); });
    }

    /** Buttons stay focusable while an action is on its way (aria-disabled), so the keyboard focus is kept */
    function setBusy(on) {
        busy = on;
        root.setAttribute('aria-busy', on ? 'true' : 'false');
        root.querySelectorAll('button').forEach(function (b) {
            if (on) b.setAttribute('aria-disabled', 'true'); else b.removeAttribute('aria-disabled');
        });
    }

    // ------------------------------------------------------------------ rendering

    function build() {
        root.textContent = '';
        parts = {
            bar: el('div', { class: 'mtw-bar', role: 'group', 'aria-label': 'Travail en équipe sur cette conversation' }),
            error: el('p', { class: 'mb-form-error mtw-error', role: 'alert', hidden: true }),
            presence: el('div', { class: 'mtw-presence-wrap', 'aria-live': 'polite' }),
            notes: el('section', { class: 'mtw-panel', id: 'mtw-notes-panel', 'aria-labelledby': 'mtw-notes-title', hidden: true }),
            notesList: el('div', { class: 'mtw-notes-list' }),
            activity: el('section', { class: 'mtw-panel', id: 'mtw-activity-panel', 'aria-labelledby': 'mtw-activity-title', hidden: true }),
            activityList: el('div', { class: 'mtw-activity-list' }),
            keys: {}
        };
        buildNotesPanel();
        parts.activity.appendChild(el('h3', { class: 'mb-h3', id: 'mtw-activity-title', text: 'Activité' }));
        parts.activity.appendChild(parts.activityList);
        [parts.bar, parts.error, parts.presence, parts.notes, parts.activity].forEach(function (p) { root.appendChild(p); });
    }

    function changed(name, value) {
        var k = JSON.stringify(value);
        if (parts.keys[name] === k) return false;
        parts.keys[name] = k;
        return true;
    }

    function render() {
        if (!state) return;
        if (!parts) build();
        root.hidden = false;

        var s = state;
        var barData = [s.status, s.done_by, s.done_at_label, s.assignee, s.assignees, s.can, s.notes.length, panel];
        // Don't close an open list of people under someone's pointer: the next poll catches up
        if (changed('bar', barData) || !parts.bar.firstChild) {
            if (document.activeElement && document.activeElement.id === 'mtw-assign') parts.keys.bar = null;   // caught up on blur
            else rebuild(parts.bar, renderBar, function () { return parts.bar.querySelector('#mtw-assign, button'); });
        }
        if (changed('presence', s.presence)) renderPresence();
        if (changed('notes', s.notes)) rebuild(parts.notesList, renderNotes, function () { return parts.area; });
        parts.notes.hidden = panel !== 'notes';
        parts.activity.hidden = panel !== 'activity';
        if (panel === 'activity' && s.activity && changed('activity', s.activity)) rebuild(parts.activityList, renderActivity);
    }

    function renderBar(bar) {
        var s = state;
        var me = null;
        (s.assignees || []).forEach(function (p) { if (p.me) me = p; });

        // Ouverte / Traitée
        if (s.status === 'done') {
            bar.appendChild(el('span', {
                class: 'mb-pill mb-pill--success mtw-status',
                title: s.done_by ? 'Marquée traitée par ' + s.done_by.name : null,
                text: 'Traitée' + (s.done_at_label ? ' · ' + s.done_at_label : '')
            }));
        } else {
            bar.appendChild(el('span', { class: 'mb-pill mtw-pill-open mtw-status', text: 'Ouverte' }));
        }

        // Assignment
        var assign = el('div', { class: 'mtw-assign' });
        if (s.can.send) {
            assign.appendChild(el('label', { for: 'mtw-assign', class: 'mtw-label', text: 'Assignée à' }));
            var select = el('select', { id: 'mtw-assign', class: 'mb-select mtw-select' });
            select.appendChild(el('option', { value: '', text: 'Personne', selected: !s.assignee }));
            var listed = false;
            (s.assignees || []).forEach(function (p) {
                var on = !!(s.assignee && s.assignee.id === p.id);
                if (on) listed = true;
                select.appendChild(el('option', { value: p.id, text: p.me ? p.name + ' (vous)' : p.name, selected: on }));
            });
            if (s.assignee && !listed) {
                select.appendChild(el('option', { value: s.assignee.id, text: s.assignee.name + ' (ne peut plus répondre)', selected: true }));
            }
            // Arrow keys on a closed list change it at every press (Windows): a keyboard choice is saved on
            // Enter, on leaving the list or after a pause, so nobody passed on the way gets a bell
            var keyAt = 0;
            var wait = null;
            var saved = s.assignee ? String(s.assignee.id) : '';
            var commit = function () {
                clearTimeout(wait);
                wait = null;
                if (select.value === saved) return;
                saved = select.value;
                act({ action: 'assign', user_id: select.value === '' ? null : Number(select.value) }, { changed: true })
                    .catch(function () { if (parts) parts.keys.bar = null; });
            };
            select.addEventListener('keydown', function (ev) {
                keyAt = Date.now();
                if (ev.key === 'Enter') { ev.preventDefault(); commit(); }
            });
            select.addEventListener('change', function () {
                if (Date.now() - keyAt > 300) { commit(); return; }
                clearTimeout(wait);
                wait = setTimeout(commit, 1500);
            });
            select.addEventListener('blur', function () {
                if (wait) commit();
                if (parts && parts.keys.bar === null) setTimeout(render, 0);
            });
            assign.appendChild(select);
            if (me && !(s.assignee && s.assignee.me)) {
                assign.appendChild(el('button', { type: 'button', class: 'mb-btn mb-btn--ghost mb-btn--sm', 'data-act': 'assign-me', text: 'M’assigner' }));
            }
        } else {
            assign.appendChild(el('span', { class: 'mtw-label', text: 'Assignée à' }));
            if (s.assignee) assign.appendChild(avatar(s.assignee));
            assign.appendChild(el('span', { class: 'mtw-who', text: s.assignee ? (s.assignee.me ? 'Vous' : s.assignee.name) : 'Personne' }));
        }
        bar.appendChild(assign);

        // Tools
        var tools = el('div', { class: 'mtw-tools' });
        if (s.can.send) {
            tools.appendChild(s.status === 'done'
                ? el('button', { type: 'button', class: 'mb-btn mb-btn--sm', 'data-act': 'status', 'data-status': 'open', text: 'Rouvrir' })
                : el('button', { type: 'button', class: 'mb-btn mb-btn--sm', 'data-act': 'status', 'data-status': 'done', text: 'Marquer traitée' }));
        }
        var notesBtn = el('button', {
            type: 'button', class: 'mb-btn mb-btn--ghost mb-btn--sm', 'data-panel': 'notes',
            'aria-expanded': panel === 'notes' ? 'true' : 'false', 'aria-controls': 'mtw-notes-panel'
        }, ['Notes']);
        if (s.notes.length) {
            notesBtn.appendChild(el('span', { class: 'mtw-count', text: String(s.notes.length) }));
            notesBtn.setAttribute('aria-label', 'Notes (' + s.notes.length + ')');
        }
        tools.appendChild(notesBtn);
        tools.appendChild(el('button', {
            type: 'button', class: 'mb-btn mb-btn--ghost mb-btn--sm', 'data-panel': 'activity',
            'aria-expanded': panel === 'activity' ? 'true' : 'false', 'aria-controls': 'mtw-activity-panel', text: 'Activité'
        }));
        bar.appendChild(tools);
    }

    function renderPresence() {
        var box = parts.presence;
        box.textContent = '';
        var replying = state.presence.filter(function (p) { return p.state === 'replying'; });
        var viewing = state.presence.filter(function (p) { return p.state !== 'replying'; });
        if (replying.length) {
            var line = el('p', { class: 'mtw-presence mtw-presence--replying' });
            line.appendChild(el('span', { class: 'mtw-dot', 'aria-hidden': 'true' }));
            replying.forEach(function (p) { line.appendChild(avatar(p, 'mtw-avatar--sm')); });
            line.appendChild(el('span', { text: names(replying) + (replying.length > 1 ? ' rédigent' : ' rédige') + ' une réponse. Accordez-vous avant d’envoyer la vôtre.' }));
            box.appendChild(line);
        }
        if (viewing.length) {
            var seen = el('p', { class: 'mtw-presence mtw-presence--viewing' });
            viewing.forEach(function (p) { seen.appendChild(avatar(p, 'mtw-avatar--sm')); });
            seen.appendChild(el('span', { text: names(viewing) + (viewing.length > 1 ? ' consultent' : ' consulte') + ' aussi cette conversation.' }));
            box.appendChild(seen);
        }
    }

    function buildNotesPanel() {
        var p = parts.notes;
        p.appendChild(el('h3', { class: 'mb-h3', id: 'mtw-notes-title', text: 'Notes de l’équipe' }));
        p.appendChild(parts.notesList);

        var form = el('form', { class: 'mtw-note-form', novalidate: true });
        var field = el('div', { class: 'mb-field' });
        field.appendChild(el('label', { for: 'mtw-note-body', text: 'Nouvelle note' }));
        var area = el('textarea', {
            id: 'mtw-note-body', name: 'body', rows: 3, maxlength: NOTE_MAX, class: 'mtw-textarea',
            placeholder: 'Par exemple : client rappelé, bon de commande attendu lundi', 'aria-describedby': 'mtw-note-hint'
        });
        area.value = drafts[current] || '';
        field.appendChild(area);
        field.appendChild(el('p', { class: 'mb-field-error', id: 'mtw-note-error', hidden: true }));
        form.appendChild(field);
        var actions = el('div', { class: 'mtw-note-actions' });
        actions.appendChild(el('p', { class: 'mb-hint', id: 'mtw-note-hint', text: 'Seule l’équipe voit les notes ; le client ne les voit jamais.' }));
        actions.appendChild(el('button', { type: 'submit', class: 'mb-btn mb-btn--primary mb-btn--sm', text: 'Ajouter la note' }));
        form.appendChild(actions);
        p.appendChild(form);

        area.addEventListener('input', function () {
            drafts[current] = area.value;
            noteError('');
        });
        area.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); submitNote(); }
        });
        form.addEventListener('submit', function (ev) { ev.preventDefault(); submitNote(); });
        parts.area = area;
    }

    function submitNote() {
        var area = parts && parts.area;
        if (!area || busy) return;
        if (area.value.trim() === '') {
            noteError('Écrivez la note avant de l’ajouter.');
            area.focus();
            return;
        }
        var thread = current;
        act({ action: 'note_add', body: area.value }, { toast: false }).then(function () {
            if (thread !== current) return;
            area.value = '';
            delete drafts[thread];
            var last = parts.notesList.querySelector('li:last-child');
            if (last) last.scrollIntoView({ block: 'nearest' });
            toast('Note ajoutée. Seule l’équipe la voit.');
            area.focus();
        }).catch(function () { area.focus(); });
    }

    function noteError(message) {
        if (!parts || !parts.area) return;
        var slot = parts.notes.querySelector('#mtw-note-error');
        slot.textContent = message;
        slot.hidden = !message;
        if (message) {
            parts.area.setAttribute('aria-invalid', 'true');
            parts.area.setAttribute('aria-describedby', 'mtw-note-error mtw-note-hint');
        } else {
            parts.area.removeAttribute('aria-invalid');
            parts.area.setAttribute('aria-describedby', 'mtw-note-hint');
        }
    }

    function renderNotes(box) {
        if (!state.notes.length) {
            box.appendChild(el('p', { class: 'mb-muted mtw-empty', text: 'Pas encore de note. Laissez-en une pour la personne qui reprendra cette conversation.' }));
            return;
        }
        var list = el('ul', { class: 'mtw-notes' });
        state.notes.forEach(function (n) {
            var head = el('div', { class: 'mtw-note-head' });
            head.appendChild(avatar({ initials: n.initials }, 'mtw-avatar--sm'));
            head.appendChild(el('strong', { text: n.mine ? 'Vous' : n.author }));
            head.appendChild(el('time', { datetime: n.at, text: n.at_label }));
            if (n.can_delete) {
                head.appendChild(el('button', {
                    type: 'button', class: 'mb-btn mb-btn--danger-ghost mb-btn--sm mtw-note-delete',
                    'data-act': 'note-delete-' + n.id, 'data-note': n.id,
                    'aria-label': (n.mine ? 'Supprimer votre note' : 'Supprimer la note de ' + n.author) + ' (' + n.at_label + ')', text: 'Supprimer'
                }));
            }
            var item = el('li', { class: 'mtw-note' }, [head, el('p', { class: 'mtw-note-body', text: n.body })]);
            list.appendChild(item);
        });
        box.appendChild(list);
    }

    function renderActivity(box) {
        var rows = state.activity || [];
        if (!rows.length) {
            box.appendChild(el('p', { class: 'mb-muted mtw-empty', text: 'Rien ne s’est encore passé sur cette conversation dans bureau.' }));
            return;
        }
        var list = el('ul', { class: 'mtw-activity' });
        rows.forEach(function (a) {
            list.appendChild(el('li', {}, [el('span', { text: a.text }), el('time', { datetime: a.at, text: a.at_label })]));
        });
        box.appendChild(list);
    }

    function showError(message) {
        if (!parts) return;
        parts.error.textContent = message || '';
        parts.error.hidden = !message;
    }

    function saveDraft() {
        if (current && parts && parts.area) {
            if (parts.area.value.trim() !== '') drafts[current] = parts.area.value;
            else delete drafts[current];
        }
    }

    // ------------------------------------------------------------------ clicks

    root.addEventListener('click', function (ev) {
        var t = ev.target.closest('button');
        if (!t || !root.contains(t) || t.type === 'submit') return;

        var which = t.getAttribute('data-panel');
        if (which) {
            panel = panel === which ? '' : which;
            parts.keys.bar = null;
            render();
            if (panel === 'activity') reload();
            if (panel === 'notes' && parts.area) parts.area.focus();
            return;
        }

        var action = t.getAttribute('data-act') || '';
        if (busy) return;
        if (action === 'assign-me') {
            var mine = (state.assignees || []).filter(function (p) { return p.me; })[0];
            if (mine) act({ action: 'assign', user_id: mine.id }, { changed: true }).catch(function () {});
        } else if (action === 'status') {
            act({ action: 'status', status: t.getAttribute('data-status') === 'done' ? 'done' : 'open' }, { changed: true }).catch(function () {});
        } else if (action.indexOf('note-delete-') === 0) {
            var noteId = Number(t.getAttribute('data-note'));
            var ask = (window.LPC && window.LPC.modal && window.LPC.modal.confirm)
                ? window.LPC.modal.confirm('Personne ne la verra plus, ni dans les notes ni ailleurs.', {
                    title: 'Supprimer cette note ?', confirmLabel: 'Supprimer la note', cancelLabel: 'Garder la note', danger: true
                })
                : Promise.resolve(window.confirm('Supprimer cette note ? Personne ne la verra plus.'));
            ask.then(function (yes) {
                if (yes) act({ action: 'note_delete', note_id: noteId }).catch(function () {});
            });
        }
    });

    // ------------------------------------------------------------------ page events

    document.addEventListener('mail:thread-opened', function (ev) {
        var d = ev.detail || {};
        open(d.threadId, d.shared === false ? false : true);
    });

    document.addEventListener('mail:composing', function (ev) {
        var d = ev.detail || {};
        if (!current || Number(d.threadId) !== current) return;
        var id = current;
        var n = ++sent;
        request('POST', { action: 'composing', thread: id, state: d.state === 'replying' ? 'replying' : 'stopped', activity: panel === 'activity' })
            .then(function (json) { apply(id, n, json.data); }, function () { /* presence is best effort */ });
    });

    document.addEventListener('mail:thread-changed', function (ev) {
        var d = ev.detail || {};
        if (d.source === 'team' || Number(d.threadId) !== current) return;
        reload();
    });

    document.addEventListener('mail:sent', function (ev) {
        var d = ev.detail || {};
        if (current && (!d.threadId || Number(d.threadId) === current)) reload();
    });

    document.addEventListener('mail:refresh', function () { reload(); });

    document.addEventListener('visibilitychange', function () {
        if (!current) return;
        if (document.hidden) { stop(); return; }
        beat(true);
        schedule();
    });

    window.addEventListener('pagehide', function () {
        if (current) leave(current, true);
    });

    // Someone is at the page: an idle pause ends at the first touch
    var lastSeen = 0;
    ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach(function (name) {
        window.addEventListener(name, function () {
            var now = Date.now();
            if (now - lastSeen < 1000) return;
            lastSeen = now;
            var wasIdle = idle();
            lastInput = now;
            if (wasIdle && current && !document.hidden) beat(true);
        }, { passive: true, capture: true });
    });

    // A conversation already on screen when the page loaded (a bell link ?thread=…)
    var first = Number(root.getAttribute('data-team-thread')) || 0;
    if (first && root.getAttribute('data-team-shared') === '1') open(first, true);
})();
