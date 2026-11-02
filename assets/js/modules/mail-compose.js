/**
 * assets/js/modules/mail-compose.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, the composer (PR 3; modules/mail/mounts/composer.php,
 * docs/MAIL.md §6.2, §6.3, §12). Plain script, no build step.
 *
 * Opens on the browser event mail:compose {mode: new|reply|reply_all|forward|draft,
 * accountId, messageId?, outboxId?, to?}. Everything is saved as the person types
 * (api/v1/mail/compose.php, one request at a time, the latest text wins); the
 * server also keeps a copy in the mailbox's Brouillons. Envoyer waits 10 seconds
 * for "Annuler" (the sending bar), then asks the server to send; if the page is
 * left before that, scripts/cron/mail_send_due.php sends it within a minute.
 *
 * Raises mail:compose-opened / mail:compose-closed {accountId, mode, messageId,
 * outboxId}, mail:sent {accountId, threadId, outboxId}, mail:composing {threadId,
 * state} (replies). Provides window.LPCMailComposer for PR 5 (current(),
 * replaceBody(html), insertHtml(html), open(detail)).
 *
 * What the editor produces is cleaned here on paste and again on the server
 * (LPC\Mail\Compose\HtmlCleaner) before it is kept or sent.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    if (window.LPCMailComposer && window.LPCMailComposer.__loaded) return;

    var dlg = document.getElementById('mail-composer');
    if (!dlg) return;

    var CFG = {};
    try { CFG = JSON.parse(document.getElementById('mail-compose-data').textContent || '{}'); } catch (e) { CFG = {}; }
    var API = CFG.api || '/api/v1/mail/compose.php';
    var EMAIL = /^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[^\s@<>(),;:"]{2,}$/;

    var $ = function (sel, root) { return (root || dlg).querySelector(sel); };
    var form = $('[data-mc-form]');
    var titleEl = $('#mc-title');
    var savedEl = $('[data-mc-saved]');
    var fromSel = $('[data-mc-from]');
    var subj = $('[data-mc-subject]');
    var editor = $('[data-mc-editor]');
    var errorEl = $('[data-mc-error]');
    var quoteBox = $('[data-mc-quote]');
    var quoteBody = $('[data-mc-quote-body]');
    var quoteOn = $('[data-mc-quote-on]');
    var sigSel = $('[data-mc-sig-style]');
    var sigPreview = $('[data-mc-sig-preview]');
    var filesEl = $('[data-mc-files]');
    var fileInput = $('[data-mc-file]');
    var linkbar = $('[data-mc-linkbar]');
    var linkInput = $('#mc-link-url');
    var schedule = $('[data-mc-schedule]');
    var scheduleAt = $('[data-mc-schedule-at]');
    var confirmBox = $('[data-mc-confirm]');
    var bar = document.querySelector('[data-mc-bar]');
    var barText = bar && bar.querySelector('[data-mc-bar-text]');
    var barAction = bar && bar.querySelector('[data-mc-bar-action]');

    var state = null;        // the draft as the server last described it
    var mode = 'new';
    var opener = null;
    var queue = Promise.resolve();
    var saveTimer = null;
    var dirty = false;
    var uploads = 0;
    var range = null;
    var barTimer = null;
    var barTick = null;

    // ------------------------------------------------------------------ helpers

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    }

    function api(body, query) {
        var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf();
            opts.body = JSON.stringify(body);
        }
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
            throw new Error('Pas de connexion avec bureau. Vérifiez le réseau, puis réessayez.');
        });
    }

    /** One request at a time for this composer, in order */
    function serial(fn) {
        var p = queue.then(fn, fn);
        queue = p.catch(function () {});
        return p;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function initials(a) {
        var base = (a.name || a.email || '').replace(/@.*$/, '').trim();
        var parts = base.split(/[\s._-]+/).filter(Boolean);
        return ((parts[0] || '@').charAt(0) + (parts[1] ? parts[1].charAt(0) : '')).toUpperCase();
    }

    /** Pasted or given HTML keeps its paragraphs, lists and links, not the page's look (the server cleans again) */
    function cleanHtml(html) {
        var doc = new DOMParser().parseFromString('<div>' + String(html || '') + '</div>', 'text/html');
        var root = doc.body.firstChild;
        root.querySelectorAll('script,style,meta,link,img,svg,iframe,object,embed,form,input,button,select,textarea,video,audio,template,noscript').forEach(function (n) { n.remove(); });
        root.querySelectorAll('*').forEach(function (n) {
            Array.prototype.slice.call(n.attributes).forEach(function (a) {
                var keep = n.tagName === 'A' && a.name === 'href' && /^(https?:|mailto:|tel:)/i.test(a.value.trim());
                if (!keep) n.removeAttribute(a.name);
            });
        });
        return root.innerHTML;
    }

    // ------------------------------------------------------------------ the sending bar

    function showBar(text, opts) {
        if (!bar) return;
        opts = opts || {};
        clearTimeout(barTimer);
        clearInterval(barTick);
        bar.classList.toggle('mc-bar--error', !!opts.error);
        barText.textContent = text;
        barAction.hidden = !opts.action;
        barAction.textContent = opts.action || '';
        barAction.onclick = opts.onAction || null;
        bar.hidden = false;
        if (opts.timeout) {
            barTimer = setTimeout(hideBar, opts.timeout);
        }
    }

    function hideBar() {
        if (!bar) return;
        clearTimeout(barTimer);
        clearInterval(barTick);
        bar.hidden = true;
    }

    if (bar) {
        bar.querySelector('[data-mc-bar-close]').addEventListener('click', function () {
            // Closing the bar during the undo window does not cancel: the message still goes
            hideBar();
        });
    }

    // ------------------------------------------------------------------ errors

    function clearErrors() {
        errorEl.hidden = true;
        errorEl.textContent = '';
        dlg.querySelectorAll('.mc-field-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        dlg.querySelectorAll('[aria-invalid="true"]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    }

    function showError(message, field) {
        var slot = field ? dlg.querySelector('[data-error-for="' + field + '"]') : null;
        if (slot) {
            if (field === 'send_at') schedule.hidden = false;
            if (field === 'cc' || field === 'bcc') showCc(true);
            slot.textContent = message;
            slot.hidden = false;
            var input = field === 'send_at' ? scheduleAt : (chips[field] ? chips[field].input : null);
            if (input) {
                input.setAttribute('aria-invalid', 'true');
                input.focus();
            }
            return;
        }
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    // ------------------------------------------------------------------ recipients (chips)

    function Chips(field) {
        var row = dlg.querySelector('[data-chips="' + field + '"]');
        this.field = field;
        this.list = [];
        this.items = [];
        this.active = -1;
        this.timer = null;
        this.ul = row.querySelector('[data-chip-list]');
        this.input = row.querySelector('.mc-chip-input');
        this.suggest = row.querySelector('.mc-suggest');
        this.error = dlg.querySelector('[data-error-for="' + field + '"]');
        var self = this;

        row.addEventListener('click', function (e) { if (e.target === row) self.input.focus(); });
        this.input.addEventListener('input', function () { self.typed(); });
        this.input.addEventListener('keydown', function (e) { self.key(e); });
        this.input.addEventListener('blur', function () {
            setTimeout(function () {
                if (self.input.value.trim()) self.add(self.input.value);
                self.close();
            }, 150);
        });
        this.input.addEventListener('paste', function (e) {
            var text = (e.clipboardData || window.clipboardData).getData('text');
            if (/[,;\n]/.test(text)) { e.preventDefault(); self.add(self.input.value + text); }
        });
        this.ul.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-remove]');
            if (b) { self.remove(Number(b.getAttribute('data-remove'))); self.input.focus(); }
        });
        this.suggest.addEventListener('mousedown', function (e) { e.preventDefault(); });
        this.suggest.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-i]');
            if (li) self.pick(self.items[Number(li.getAttribute('data-i'))]);
        });
    }

    Chips.prototype.set = function (list) {
        this.list = (list || []).slice();
        this.input.value = '';
        this.render();
    };

    Chips.prototype.render = function () {
        this.ul.innerHTML = this.list.map(function (a, i) {
            var label = a.name ? a.name : a.email;
            return '<li class="mc-chip" title="' + esc(a.name ? a.name + ' <' + a.email + '>' : a.email) + '">'
                + '<span class="mc-chip-text">' + esc(label) + '</span>'
                + '<button type="button" data-remove="' + i + '" aria-label="Retirer ' + esc(label) + '">×</button></li>';
        }).join('');
    };

    Chips.prototype.changed = function () {
        this.render();
        if (this.error && !this.error.hidden && this.list.length) { this.error.hidden = true; this.input.removeAttribute('aria-invalid'); }
        touch();
    };

    Chips.prototype.parse = function (text) {
        text = String(text || '').trim().replace(/^[,;]+|[,;]+$/g, '').trim();
        if (!text) return null;
        var m = text.match(/^\s*"?([^"<]*?)"?\s*<([^<>]+)>\s*$/);
        var name = m ? m[1].trim() : null;
        var email = (m ? m[2] : text).trim().toLowerCase();
        return EMAIL.test(email) ? { name: name || null, email: email } : null;
    };

    /** Add what was typed or pasted: good addresses become chips, the rest stays in the field with a message */
    Chips.prototype.add = function (text) {
        var parts = String(text || '').split(/[,;\n]+(?=(?:[^"]*"[^"]*")*[^"]*$)/);
        var left = [];
        var added = false;
        for (var i = 0; i < parts.length; i++) {
            if (!parts[i].trim()) continue;
            var a = this.parse(parts[i]);
            if (!a) { left.push(parts[i].trim()); continue; }
            if (!this.list.some(function (x) { return x.email === a.email; })) this.list.push(a);
            added = true;
        }
        this.input.value = left.join(', ');
        this.close();
        if (left.length && this.error) {
            this.error.textContent = '« ' + left[0] + ' » n’est pas une adresse e-mail. Vérifiez-la, puis réessayez.';
            this.error.hidden = false;
            this.input.setAttribute('aria-invalid', 'true');
        } else if (this.error) {
            this.error.hidden = true;
            this.input.removeAttribute('aria-invalid');
        }
        if (added) this.changed();
        return added;
    };

    Chips.prototype.pick = function (item) {
        if (!item) return;
        if (!this.list.some(function (x) { return x.email === item.email; })) this.list.push({ name: item.name || null, email: item.email });
        this.input.value = '';
        this.close();
        this.changed();
        this.input.focus();
    };

    Chips.prototype.remove = function (i) {
        this.list.splice(i, 1);
        this.changed();
    };

    Chips.prototype.typed = function () {
        var self = this;
        if (this.error && !this.error.hidden) { this.error.hidden = true; this.input.removeAttribute('aria-invalid'); }
        if (/[,;]\s*$/.test(this.input.value)) { this.add(this.input.value); return; }
        clearTimeout(this.timer);
        var q = this.input.value.trim();
        if (q.length < 2) { this.close(); return; }
        this.timer = setTimeout(function () {
            api(null, 'action=suggest&q=' + encodeURIComponent(q)).then(function (r) {
                if (self.input.value.trim() !== q) return;
                var taken = self.list.map(function (x) { return x.email; });
                self.items = (r.data || []).filter(function (s) { return taken.indexOf(s.email) === -1; });
                self.active = self.items.length ? 0 : -1;
                self.open();
            }).catch(function () { self.close(); });
        }, 200);
    };

    Chips.prototype.open = function () {
        var self = this;
        if (!this.items.length) { this.close(); return; }
        var kinds = { mailbox: 'Boîte partagée', staff: 'Collègue', contact: '' };
        this.suggest.innerHTML = this.items.map(function (s, i) {
            return '<li role="option" id="mc-' + self.field + '-opt-' + i + '" data-i="' + i + '" aria-selected="' + (i === self.active) + '">'
                + '<span class="mc-suggest-avatar mc-suggest-avatar--' + esc(s.kind) + '" aria-hidden="true">' + esc(initials(s)) + '</span>'
                + '<span class="mc-suggest-who"><span class="mc-suggest-name">' + esc(s.name || s.email) + '</span>'
                + '<span class="mc-suggest-mail">' + esc(s.email) + (kinds[s.kind] ? ' · ' + kinds[s.kind] : '') + '</span></span></li>';
        }).join('');
        this.suggest.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
        this.highlight();
    };

    Chips.prototype.close = function () {
        this.items = [];
        this.active = -1;
        this.suggest.hidden = true;
        this.suggest.innerHTML = '';
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
    };

    Chips.prototype.highlight = function () {
        var self = this;
        this.suggest.querySelectorAll('li').forEach(function (li, i) { li.setAttribute('aria-selected', String(i === self.active)); });
        if (this.active >= 0) {
            this.input.setAttribute('aria-activedescendant', 'mc-' + this.field + '-opt-' + this.active);
            var li = this.suggest.querySelector('[data-i="' + this.active + '"]');
            if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
        }
    };

    Chips.prototype.key = function (e) {
        var open = !this.suggest.hidden && this.items.length;
        if (e.key === 'ArrowDown' && open) { e.preventDefault(); this.active = (this.active + 1) % this.items.length; this.highlight(); }
        else if (e.key === 'ArrowUp' && open) { e.preventDefault(); this.active = (this.active - 1 + this.items.length) % this.items.length; this.highlight(); }
        else if (e.key === 'Enter' || (e.key === 'Tab' && this.input.value.trim())) {
            if (open && this.active >= 0) { e.preventDefault(); this.pick(this.items[this.active]); }
            else if (this.input.value.trim()) { e.preventDefault(); this.add(this.input.value); }
            else if (e.key === 'Enter') { e.preventDefault(); }
        }
        else if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); this.close(); suppressCancel = true; }
        else if (e.key === 'Backspace' && !this.input.value && this.list.length) { this.remove(this.list.length - 1); }
    };

    /** Typed text not yet turned into a chip becomes one before anything is saved or sent */
    Chips.prototype.commit = function () {
        if (this.input.value.trim()) this.add(this.input.value);
        return !this.input.value.trim();
    };

    var chips = { to: new Chips('to'), cc: new Chips('cc'), bcc: new Chips('bcc') };
    var suppressCancel = false;

    function showCc(on) {
        dlg.querySelector('[data-row="cc"]').hidden = !on;
        dlg.querySelector('[data-row="bcc"]').hidden = !on;
        var b = dlg.querySelector('[data-mc="show-cc"]');
        b.setAttribute('aria-expanded', String(on));
        b.hidden = on;
    }

    // ------------------------------------------------------------------ saving

    function fields() {
        return {
            to: chips.to.list, cc: chips.cc.list, bcc: chips.bcc.list,
            subject: subj.value, html: editor.innerHTML, signature: sigSel.value,
            quote: !quoteBox.hidden && quoteOn.checked
        };
    }

    function touch() {
        if (!state) return;
        dirty = true;
        savedEl.textContent = '';
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 1200);
    }

    function save(extra) {
        clearTimeout(saveTimer);
        if (!state || (!dirty && !extra)) return Promise.resolve();
        var id = state.id;
        dirty = false;
        var body = { action: 'save', outboxId: id, fields: Object.assign(fields(), extra || {}) };
        savedEl.textContent = 'Enregistrement…';
        return serial(function () {
            return api(body).then(function (r) {
                if (!state || state.id !== id) return r;
                savedEl.textContent = dirty ? '' : 'Brouillon enregistré à ' + r.savedAt;
                Object.keys(r.invalid || {}).forEach(function (f) {
                    showError('« ' + r.invalid[f][0] + ' » n’est pas une adresse e-mail. Vérifiez-la, puis réessayez.', f);
                });
                if (r.draft) applyAccount(r.draft);
                return r;
            }, function (err) {
                if (state && state.id === id) {
                    savedEl.textContent = '';
                    showError(err.message, err.field === 'account_id' ? 'account_id' : null);
                    dirty = true;
                }
                throw err;
            });
        });
    }

    // ------------------------------------------------------------------ open and fill

    function setTitle(t) {
        titleEl.textContent = t;
    }

    function fill(d) {
        state = d;
        dirty = false;
        clearErrors();
        setTitle(d.title);
        fromSel.innerHTML = (d.accounts || []).map(function (a) {
            return '<option value="' + a.id + '"' + (a.id === d.accountId ? ' selected' : '') + '>' + esc(a.name ? a.name + ' <' + a.email + '>' : a.email) + '</option>';
        }).join('');
        fromSel.disabled = (d.accounts || []).length < 2;
        chips.to.set(d.to);
        chips.cc.set(d.cc);
        chips.bcc.set(d.bcc);
        showCc(!!(d.cc.length || d.bcc.length));
        subj.value = d.subject || '';
        editor.innerHTML = d.html || '';
        sigSel.value = d.signature || 'full';
        quoteBox.hidden = !d.quoteHtml;
        quoteBody.innerHTML = d.quoteHtml || '';   // built on the server from escaped text
        quoteBody.hidden = true;
        quoteOn.checked = !!d.quote;
        quoteBox.classList.toggle('is-off', !d.quote);
        dlg.querySelector('[data-mc="toggle-quote"]').setAttribute('aria-expanded', 'false');
        dlg.querySelector('[data-mc="toggle-quote"]').textContent = d.kind === 'forward' ? 'Afficher le message transféré' : 'Afficher le message d’origine';
        renderSignature();
        renderFiles(d.attachments || []);
        savedEl.textContent = d.savedAt ? 'Brouillon enregistré à ' + d.savedAt : '';
        if (d.lastError) showError(d.lastError);
        schedule.hidden = true;
        confirmBox.hidden = true;
        linkbar.hidden = true;
    }

    function applyAccount(d) {
        state.accountId = d.accountId;
        state.signatures = d.signatures;
        state.accounts = d.accounts;
        renderSignature();
    }

    function renderSignature() {
        var s = sigSel.value;
        sigPreview.innerHTML = s === 'none' ? '' : ((state && state.signatures && state.signatures[s]) || '');
    }

    function focusFirst() {
        if (!chips.to.list.length) chips.to.input.focus();
        else if (!subj.value.trim()) subj.focus();
        else {
            editor.focus();
            var sel = window.getSelection();
            var r = document.createRange();
            r.selectNodeContents(editor);
            r.collapse(true);
            sel.removeAllRanges();
            sel.addRange(r);
        }
    }

    function disable(on) {
        form.querySelectorAll('input, select, button, [contenteditable]').forEach(function (el) {
            if (el.hasAttribute('contenteditable')) el.setAttribute('contenteditable', on ? 'false' : 'true');
            else if (!el.matches('[data-mc="close"]')) el.disabled = on;
        });
        if (!on) fromSel.disabled = !state || (state.accounts || []).length < 2;
    }

    function info(detail) {
        return { accountId: state ? state.accountId : (detail && detail.accountId), mode: mode, messageId: state ? state.messageId : null, outboxId: state ? state.id : null };
    }

    function replying(on) {
        if (state && state.threadId && (state.kind === 'reply' || state.kind === 'reply_all')) {
            emit('mail:composing', { threadId: state.threadId, state: on ? 'replying' : 'stopped' });
        }
    }

    function open(detail) {
        detail = detail || {};
        var start = function () {
            mode = detail.mode || 'new';
            if (!dlg.open) opener = document.activeElement;
            state = null;
            fill({ title: { reply: 'Répondre', reply_all: 'Répondre à tous', forward: 'Transférer', draft: 'Brouillon' }[mode] || 'Nouveau message',
                to: [], cc: [], bcc: [], accounts: [], attachments: [], signatures: {} });
            state = null;
            savedEl.textContent = 'Ouverture…';
            disable(true);
            if (!dlg.open) dlg.showModal();
            hideBar();
            var body = { action: 'open', mode: mode, accountId: detail.accountId || CFG.accountId || null, messageId: detail.messageId || null, outboxId: detail.outboxId || null, to: detail.to || null };
            return serial(function () { return api(body); }).then(function (r) {
                disable(false);
                fill(r.draft);
                focusFirst();
                replying(true);
                emit('mail:compose-opened', info());
            }, function (err) {
                disable(false);
                state = null;
                dlg.close();
                showBar(err.message, { error: true, timeout: 9000 });
                restoreFocus();
            });
        };
        // Already writing something else: keep it (as a draft) first
        if (dlg.open && state) {
            return leave('close').then(start, start);
        }
        return start();
    }

    function restoreFocus() {
        if (opener && opener.focus && document.contains(opener)) {
            try { opener.focus(); } catch (e) { /* gone */ }
        }
        opener = null;
    }

    /** Close the composer after an action: 'close' keeps a draft worth keeping, 'none' just closes */
    function leave(action) {
        var d = state;
        if (!d) { if (dlg.open) dlg.close(); return Promise.resolve(); }
        Object.keys(chips).forEach(function (k) { chips[k].commit(); });
        clearTimeout(saveTimer);
        var p = action === 'close'
            ? serial(function () { return api({ action: 'close', outboxId: d.id, fields: fields() }); })
            : Promise.resolve({});
        replying(false);
        var closed = info();
        state = null;
        dirty = false;
        if (dlg.open) dlg.close();
        emit('mail:compose-closed', closed);
        restoreFocus();
        return p.then(function (r) {
            if (r && r.kept) showBar(r.message || 'Brouillon enregistré.', { timeout: 4000 });
            return r;
        }, function (err) {
            showBar('Le brouillon n’a pas pu être enregistré : ' + err.message, { error: true, action: 'Rouvrir', onAction: function () { open({ mode: 'draft', outboxId: d.id }); } });
        });
    }

    // ------------------------------------------------------------------ send, undo, schedule

    function checkBeforeSend() {
        var ok = true;
        Object.keys(chips).forEach(function (k) { if (!chips[k].commit()) ok = false; });
        if (!ok) return false;
        if (!chips.to.list.length && !chips.cc.list.length && !chips.bcc.list.length) {
            showError('Ajoutez au moins un destinataire.', 'to');
            return false;
        }
        if (uploads > 0) {
            showError('Un fichier est encore en cours d’ajout. Attendez un instant, puis envoyez.');
            return false;
        }
        return true;
    }

    function send() {
        if (!state) return;
        clearErrors();
        if (!checkBeforeSend()) return;
        var d = state;
        clearTimeout(saveTimer);
        var sendBtn = dlg.querySelector('[data-mc="send"]');
        sendBtn.disabled = true;
        serial(function () { return api({ action: 'send', outboxId: d.id, fields: fields() }); }).then(function (r) {
            sendBtn.disabled = false;
            replying(false);
            var closed = info();
            state = null;
            dirty = false;
            dlg.close();
            emit('mail:compose-closed', closed);
            emit('mail:sent', { accountId: r.accountId, threadId: r.threadId, outboxId: r.outboxId });
            restoreFocus();
            countdown(r);
        }, function (err) {
            sendBtn.disabled = false;
            showError(err.message, err.field);
        });
    }

    function countdown(r) {
        var end = Date.now() + (r.seconds || CFG.undoSeconds || 10) * 1000;
        var tick = function () {
            var left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
            barText.textContent = left > 0 ? 'Envoi dans ' + left + ' s…' : 'Envoi…';
            if (left === 0) {
                clearInterval(barTick);
                barAction.hidden = true;
                deliver(r);
            }
        };
        showBar('', { action: 'Annuler', onAction: function () { undo(r.outboxId); } });
        tick();
        barTick = setInterval(tick, 250);
    }

    function deliver(r) {
        api({ action: 'deliver', outboxId: r.outboxId }).then(function (res) {
            emit('mail:sent', { accountId: res.accountId, threadId: res.threadId, outboxId: res.outboxId });
            if (res.state === 'failed') {
                showBar(res.message, { error: true, action: 'Ouvrir', onAction: function () { open({ mode: 'draft', outboxId: r.outboxId }); } });
            } else if (res.state === 'retry') {
                showBar(res.message, { timeout: 9000 });
            } else {
                showBar(res.message, { timeout: 5000 });
            }
        }, function () {
            // The cron sends it within a minute
            showBar('Le message part dans la minute.', { timeout: 6000 });
        });
    }

    function undo(outboxId) {
        clearInterval(barTick);
        barAction.hidden = true;
        barText.textContent = 'Annulation…';
        api({ action: 'undo', outboxId: outboxId }).then(function (r) {
            if (!r.undone) { showBar(r.message, { timeout: 6000 }); return; }
            hideBar();
            emit('mail:sent', { accountId: r.draft.accountId, threadId: r.draft.threadId, outboxId: outboxId });
            mode = 'draft';
            opener = document.activeElement;
            fill(r.draft);
            disable(false);
            if (!dlg.open) dlg.showModal();
            focusFirst();
            replying(true);
            emit('mail:compose-opened', info());
        }, function (err) {
            showBar(err.message, { error: true, timeout: 9000 });
        });
    }

    function scheduleSend(quick) {
        if (!state) return;
        clearErrors();
        if (!checkBeforeSend()) return;
        var body = { action: 'schedule', outboxId: state.id, fields: fields() };
        if (quick) body.quick = quick;
        else {
            if (!scheduleAt.value) { showError('Choisissez un jour et une heure.', 'send_at'); return; }
            body.at = scheduleAt.value;
        }
        clearTimeout(saveTimer);
        serial(function () { return api(body); }).then(function (r) {
            replying(false);
            var closed = info();
            state = null;
            dirty = false;
            dlg.close();
            emit('mail:compose-closed', closed);
            emit('mail:sent', { accountId: r.accountId, threadId: r.threadId, outboxId: r.outboxId });
            restoreFocus();
            showBar(r.message, { action: 'Annuler', onAction: function () { undo(r.outboxId); }, timeout: 12000 });
        }, function (err) {
            showError(err.message, err.field);
        });
    }

    function discard() {
        var d = state;
        if (!d) return;
        clearTimeout(saveTimer);
        replying(false);
        var closed = info();
        state = null;
        dirty = false;
        dlg.close();
        emit('mail:compose-closed', closed);
        restoreFocus();
        serial(function () { return api({ action: 'discard', outboxId: d.id }); }).then(function (r) {
            showBar(r.message, { timeout: 4000 });
        }, function (err) {
            showBar(err.message, { error: true, timeout: 9000 });
        });
    }

    // ------------------------------------------------------------------ files

    function fileIcon() {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5"/></svg>';
    }

    function renderFiles(list) {
        filesEl.querySelectorAll('[data-key]').forEach(function (li) { li.remove(); });
        list.forEach(addFileChip);
    }

    function addFileChip(a) {
        var li = document.createElement('li');
        li.className = 'mc-file';
        li.setAttribute('data-key', a.key);
        li.setAttribute('data-size', a.size);
        li.innerHTML = fileIcon() + '<span class="mc-file-name" title="' + esc(a.name) + '">' + esc(a.name) + '</span>'
            + '<span class="mc-file-size">' + esc(a.sizeLabel) + '</span>'
            + (a.source === 'original' ? '<span class="mc-file-tag">du message d’origine</span>' : '')
            + '<button type="button" data-remove-file aria-label="Retirer ' + esc(a.name) + '">×</button>';
        filesEl.appendChild(li);
        return li;
    }

    function filesBytes() {
        var n = 0;
        filesEl.querySelectorAll('[data-size]').forEach(function (li) { n += Number(li.getAttribute('data-size')) || 0; });
        return n;
    }

    function upload(files) {
        if (!state) return;
        var slot = dlg.querySelector('[data-error-for="attachments"]');
        slot.hidden = true;
        Array.prototype.forEach.call(files, function (file) {
            if (filesBytes() + file.size > (CFG.maxBytes || 0)) {
                slot.textContent = '« ' + file.name + ' » ferait dépasser ' + (CFG.maxLabel || 'la limite') + ' de pièces jointes. Partagez un lien vers les gros fichiers à la place.';
                slot.hidden = false;
                return;
            }
            var li = document.createElement('li');
            li.className = 'mc-file is-uploading';
            li.setAttribute('data-size', file.size);
            li.innerHTML = fileIcon() + '<span class="mc-file-name">' + esc(file.name) + '</span><span class="mc-progress" aria-hidden="true"><span></span></span><span class="mc-file-size">Ajout…</span>';
            filesEl.appendChild(li);
            uploads++;
            var id = state.id;
            serial(function () {
                return new Promise(function (resolve) {
                    var fd = new FormData();
                    fd.append('action', 'upload');
                    fd.append('outboxId', id);
                    fd.append('file', file, file.name);
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', API);
                    xhr.setRequestHeader('X-CSRF-Token', csrf());
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.onprogress = function (e) {
                        if (e.lengthComputable) li.querySelector('.mc-progress span').style.width = Math.round(e.loaded / e.total * 100) + '%';
                    };
                    xhr.onload = function () {
                        var json = {};
                        try { json = JSON.parse(xhr.responseText); } catch (e) { json = {}; }
                        uploads--;
                        li.remove();
                        if (xhr.status === 200 && json.status === 'success') {
                            if (state && state.id === id) addFileChip(json.attachment);
                        } else if (state && state.id === id) {
                            slot.textContent = '« ' + file.name + ' » : ' + (json.message || (xhr.status === 413 ? 'ce fichier est trop lourd pour être joint.' : 'le fichier n’a pas pu être ajouté. Réessayez.'));
                            slot.hidden = false;
                        }
                        resolve();
                    };
                    xhr.onerror = function () {
                        uploads--;
                        li.remove();
                        slot.textContent = '« ' + file.name + ' » n’a pas pu être envoyé à bureau. Vérifiez le réseau, puis réessayez.';
                        slot.hidden = false;
                        resolve();
                    };
                    xhr.send(fd);
                });
            });
        });
    }

    filesEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove-file]');
        if (!b || !state) return;
        var li = b.closest('[data-key]');
        var key = li.getAttribute('data-key');
        var id = state.id;
        li.remove();
        serial(function () { return api({ action: 'remove_attachment', outboxId: id, key: key }); }).catch(function (err) { showError(err.message); });
    });

    fileInput.addEventListener('change', function () {
        upload(fileInput.files);
        fileInput.value = '';
    });

    var dropZone = $('[data-mc-drop]');
    dropZone.addEventListener('dragover', function (e) {
        if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1) {
            e.preventDefault();
            dropZone.classList.add('is-drop');
        }
    });
    dropZone.addEventListener('dragleave', function (e) { if (!dropZone.contains(e.relatedTarget)) dropZone.classList.remove('is-drop'); });
    dropZone.addEventListener('drop', function (e) {
        dropZone.classList.remove('is-drop');
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            e.preventDefault();
            upload(e.dataTransfer.files);
        }
    });

    // ------------------------------------------------------------------ editor

    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers: <div> */ }

    function saveRange() {
        var sel = window.getSelection();
        if (sel && sel.rangeCount && editor.contains(sel.anchorNode)) range = sel.getRangeAt(0).cloneRange();
    }

    function restoreRange() {
        editor.focus();
        if (!range) return;
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function cmd(name, value) {
        restoreRange();
        if (name === 'formatBlock') {
            var inQuote = document.queryCommandValue('formatBlock').toLowerCase() === 'blockquote';
            document.execCommand('formatBlock', false, inQuote ? 'p' : value);
        } else {
            document.execCommand(name, false, value === undefined ? null : value);
        }
        saveRange();
        pressed();
        touch();
    }

    function pressed() {
        dlg.querySelectorAll('[data-cmd]').forEach(function (b) {
            var c = b.getAttribute('data-cmd');
            if (['bold', 'italic', 'underline', 'insertUnorderedList', 'insertOrderedList'].indexOf(c) === -1) return;
            var on = false;
            try { on = document.queryCommandState(c); } catch (e) { on = false; }
            b.setAttribute('aria-pressed', String(!!on));
        });
    }

    editor.addEventListener('input', touch);
    editor.addEventListener('keyup', pressed);
    editor.addEventListener('mouseup', pressed);
    editor.addEventListener('blur', saveRange);
    editor.addEventListener('paste', function (e) {
        var data = e.clipboardData;
        if (!data) return;
        var html = data.getData('text/html');
        if (!html) return;   // plain text: the browser inserts it as text
        e.preventDefault();
        document.execCommand('insertHTML', false, cleanHtml(html));
    });

    function openLink() {
        saveRange();
        linkInput.value = '';
        linkbar.hidden = false;
        linkInput.focus();
    }

    function applyLink() {
        var url = linkInput.value.trim();
        linkbar.hidden = true;
        if (!url) { restoreRange(); return; }
        if (!/^(https?:|mailto:|tel:)/i.test(url)) url = (EMAIL.test(url) ? 'mailto:' : 'https://') + url;
        if (!range || range.collapsed) {
            cmd('insertHTML', '<a href="' + esc(url) + '">' + esc(url.replace(/^mailto:/i, '')) + '</a>&nbsp;');
        } else {
            cmd('createLink', url);
        }
    }

    linkInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); suppressCancel = true; linkbar.hidden = true; restoreRange(); }
    });

    // Toolbar buttons keep the editor's selection (mousedown would move focus away)
    $('[data-mc-toolbar]').addEventListener('mousedown', function (e) {
        if (e.target.closest('[data-cmd], [data-mc="link"]')) e.preventDefault();
    });

    // ------------------------------------------------------------------ wiring

    form.addEventListener('click', function (e) {
        var c = e.target.closest('[data-cmd]');
        if (c) { cmd(c.getAttribute('data-cmd'), c.getAttribute('data-value') || undefined); return; }
        var b = e.target.closest('[data-mc]');
        if (!b) {
            if (!e.target.closest('[data-mc-schedule]')) schedule.hidden = true;
            return;
        }
        var what = b.getAttribute('data-mc');
        if (what !== 'schedule-menu' && !b.closest('[data-mc-schedule]')) closeSchedule();
        switch (what) {
            case 'close': leave('close'); break;
            case 'send': send(); break;
            case 'show-cc': showCc(true); chips.cc.input.focus(); break;
            case 'link': openLink(); break;
            case 'link-apply': applyLink(); break;
            case 'link-cancel': linkbar.hidden = true; restoreRange(); break;
            case 'toggle-quote':
                quoteBody.hidden = !quoteBody.hidden;
                b.setAttribute('aria-expanded', String(!quoteBody.hidden));
                b.textContent = (quoteBody.hidden ? 'Afficher ' : 'Masquer ') + (state && state.kind === 'forward' ? 'le message transféré' : 'le message d’origine');
                break;
            case 'schedule-menu': toggleSchedule(); break;
            case 'schedule': scheduleSend(null); break;
            case 'discard': confirmBox.hidden = false; confirmBox.querySelector('[data-mc="discard-cancel"]').focus(); break;
            case 'discard-cancel': confirmBox.hidden = true; dlg.querySelector('[data-mc="discard"]').focus(); break;
            case 'discard-yes': discard(); break;
        }
    });

    schedule.addEventListener('click', function (e) {
        var q = e.target.closest('[data-quick]');
        if (q) scheduleSend(q.getAttribute('data-quick'));
    });

    function toggleSchedule() {
        var btn = dlg.querySelector('[data-mc="schedule-menu"]');
        var show = schedule.hidden;
        schedule.hidden = !show;
        btn.setAttribute('aria-expanded', String(show));
        if (show) {
            var d = new Date(Date.now() + 86400000);
            d.setHours(8, 0, 0, 0);
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var local = function (x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()) + 'T' + pad(x.getHours()) + ':' + pad(x.getMinutes()); };
            if (!scheduleAt.value) scheduleAt.value = local(d);
            scheduleAt.min = local(new Date(Date.now() + 120000));
            schedule.querySelector('[data-quick]').focus();
        }
    }

    function closeSchedule() {
        schedule.hidden = true;
        dlg.querySelector('[data-mc="schedule-menu"]').setAttribute('aria-expanded', 'false');
    }

    subj.addEventListener('input', touch);
    subj.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); editor.focus(); } });
    sigSel.addEventListener('change', function () { renderSignature(); touch(); });
    quoteOn.addEventListener('change', function () { quoteBox.classList.toggle('is-off', !quoteOn.checked); touch(); });
    fromSel.addEventListener('change', function () {
        if (!state) return;
        save({ account_id: Number(fromSel.value) }).catch(function () {
            fromSel.value = String(state ? state.accountId : '');
        });
    });

    dlg.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); return; }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K') && editor.contains(document.activeElement)) { e.preventDefault(); openLink(); return; }
        if (e.key === 'Escape' && !schedule.hidden) { e.preventDefault(); suppressCancel = true; closeSchedule(); dlg.querySelector('[data-mc="schedule-menu"]').focus(); return; }
        if (e.key === 'Escape' && !confirmBox.hidden) { e.preventDefault(); suppressCancel = true; confirmBox.hidden = true; }
    });

    // Échap closes the composer and keeps the draft (unless a list or menu inside was open)
    dlg.addEventListener('cancel', function (e) {
        e.preventDefault();
        if (suppressCancel) { suppressCancel = false; return; }
        leave('close');
    });
    // Chrome closes a modal dialog on Échap without "cancel" when nothing was clicked or typed since it
    // opened: the draft is still kept the same way (leave() clears state before it closes the dialog itself)
    dlg.addEventListener('close', function () {
        if (state) leave('close');
    });

    // Leaving the page while something is unsaved: send it with the page's last breath
    window.addEventListener('pagehide', function () {
        if (!state || !dirty || !navigator.sendBeacon) return;
        var body = JSON.stringify({ action: 'save', outboxId: state.id, fields: fields(), _csrf: csrf() });
        navigator.sendBeacon(API, new Blob([body], { type: 'application/json' }));
    });

    document.addEventListener('mail:compose', function (e) { open(e.detail || {}); });

    // ------------------------------------------------------------------ for PR 5 (docs/MAIL.md §6.3)

    window.LPCMailComposer = {
        __loaded: true,
        current: function () {
            if (!state || !dlg.open) return null;
            return {
                accountId: state.accountId, mode: mode, messageId: state.messageId, outboxId: state.id,
                subject: subj.value, html: editor.innerHTML, text: editor.innerText
            };
        },
        replaceBody: function (html) {
            if (!state) return;
            editor.innerHTML = cleanHtml(html);
            touch();
        },
        insertHtml: function (html) {
            if (!state) return;
            restoreRange();
            document.execCommand('insertHTML', false, cleanHtml(html));
            saveRange();
            touch();
        },
        open: open
    };
})();
