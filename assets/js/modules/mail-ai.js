/**
 * assets/js/modules/mail-ai.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Praxis AI (PR 5; docs/MAIL.md §6.2, §6.3, §7.6).
 * Loaded once by whichever of the three mounts comes first (LPC\Mail\Ai\Ui):
 *
 *   Résumer                  [data-mai-summary] above the messages: follows
 *                            mail:thread-opened (and the page's ?thread=), hides
 *                            in Indésirables and Corbeille, raises
 *                            mail:thread-changed after a summary is saved.
 *   Demander à votre courrier  #mai-ask: the panel; follows the open mailbox
 *                            (accountId of mail:thread-opened / mail:refresh).
 *   Écrire avec l'IA         #mai-write: reads and writes the draft only through
 *                            window.LPCMailComposer (PR 3); resets on
 *                            mail:compose-opened / mail:compose-closed.
 *
 * Everything goes to api/v1/mail/ai.php (JSON, X-CSRF-Token). What the model
 * wrote is shown with textContent, except the Ask answer, which the server has
 * already escaped (MailAsk::answerHtml). Nothing is kept in the browser.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var DATA = {};
    try { DATA = JSON.parse(document.getElementById('mail-ai-data').textContent || '{}'); } catch (e) { DATA = {}; }
    var API = DATA.api || '/api/v1/mail/ai.php';
    var state = { accountId: DATA.accountId || null, threadId: null, folderRole: DATA.folder || 'inbox' };
    var boxes = {};
    (DATA.boxes || []).forEach(function (b) { boxes[b.id] = b.email; });

    // Fixed markup only (never anything from the server)
    var ICONS = {
        sparkles: '<svg class="mai-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/></svg>',
        chevron: '<svg class="mai-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>'
    };

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') node.textContent = attrs[k];
            else if (k === 'icon') node.insertAdjacentHTML('afterbegin', ICONS[attrs[k]]);
            else if (attrs[k] === true) node.setAttribute(k, '');
            else if (attrs[k] !== false && attrs[k] !== null && attrs[k] !== undefined) node.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return node;
    }

    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || {};
        return c.token || '';
    }

    function toast(message, kind) {
        if (window.LPC && window.LPC.toast) {
            (window.LPC.toast[kind || 'success'] || window.LPC.toast)(message);
        }
    }

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail }));
    }

    function api(method, body, query) {
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 60000) : null;
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf();
            opts.body = JSON.stringify(body || {});
        }
        return fetch(API + (query ? '?' + query : ''), opts).then(function (res) {
            clearTimeout(timer);
            return res.json().catch(function () { return {}; }).then(function (json) {
                if (!res.ok || json.status !== 'success') {
                    var err = new Error(json.message || 'Bureau n’a pas pu terminer. Réessayez dans un instant.');
                    err.field = json.field || null;
                    throw err;
                }
                return json;
            });
        }, function (e) {
            clearTimeout(timer);
            throw new Error(e && e.name === 'AbortError'
                ? 'Praxis AI met trop de temps à répondre. Réessayez dans une minute.'
                : 'Pas de connexion avec bureau. Vérifiez le réseau, puis réessayez.');
        });
    }

    function fieldError(scope, field, message) {
        var slot = scope.querySelector('[data-error-for="' + field + '"]');
        if (!slot) return false;
        var input = slot.parentElement.querySelector('textarea, input');
        slot.textContent = message || '';
        slot.hidden = !message;
        if (!slot.id && input) slot.id = input.id + '-err';
        if (input) {
            if (message) {
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', slot.id + ' ' + input.id + '-hint');
                input.focus();
            } else {
                input.removeAttribute('aria-invalid');
                input.setAttribute('aria-describedby', input.id + '-hint');
            }
        }
        return true;
    }

    // ------------------------------------------------------------------ dialogs

    function prepareDialog(dlg) {
        if (!dlg) return null;
        // Out of the composer or toolbar it was printed in: no form around it, none of their key handlers
        if (dlg.parentNode !== document.body) document.body.appendChild(dlg);
        dlg.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-mai-close]') || ev.target === dlg) dlg.close();
        });
        dlg.addEventListener('close', function () {
            if (dlg._opener && document.contains(dlg._opener)) dlg._opener.focus();
        });
        return dlg;
    }

    function openDialog(dlg, opener, focus) {
        dlg._opener = opener || document.activeElement;
        if (!dlg.open) dlg.showModal();
        if (focus) setTimeout(function () { focus.focus(); }, 0);
    }

    function onEnter(textarea, fn) {
        textarea.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
                ev.preventDefault();
                fn();
            }
        });
    }

    // ================================================================== Résumer

    var sumRoot = document.querySelector('[data-mai-summary]');
    var sumSeq = 0;
    var collapsed = {};

    function summaryFor(threadId, folderRole) {
        if (!sumRoot) return;
        var seq = ++sumSeq;
        state.threadId = threadId || null;
        if (!threadId || folderRole === 'spam' || folderRole === 'trash') {
            sumRoot.hidden = true;
            sumRoot.textContent = '';
            return;
        }
        api('GET', null, 'action=summary&thread=' + encodeURIComponent(threadId)).then(function (json) {
            if (seq === sumSeq) renderSummary(threadId, json.data || {});
        }, function () {
            if (seq === sumSeq) { sumRoot.hidden = true; sumRoot.textContent = ''; }
        });
    }

    function list(title, items) {
        if (!items || !items.length) return null;
        return el('div', {}, [
            el('h4', { text: title }),
            el('ul', {}, items.map(function (t) { return el('li', { text: t }); }))
        ]);
    }

    function renderSummary(threadId, data) {
        sumRoot.textContent = '';
        if (data.summary) {
            var s = data.summary;
            var bodyId = 'mai-sum-body-' + threadId;
            var isCollapsed = !!collapsed[threadId];
            var toggle = el('button', {
                type: 'button', 'class': 'mai-toggle', icon: 'chevron', 'aria-controls': bodyId,
                'aria-expanded': isCollapsed ? 'false' : 'true',
                'aria-label': isCollapsed ? 'Afficher le résumé' : 'Masquer le résumé',
                title: isCollapsed ? 'Afficher le résumé' : 'Masquer le résumé'
            });
            var foot = 'Rédigé par Praxis AI à partir de cette conversation' + (data.at_label ? ', le ' + data.at_label : '')
                + (data.by_name ? ' (demandé par ' + data.by_name + ')' : '') + '. Vérifiez les messages avant d’agir. Un nouveau message l’efface.';
            var body = el('div', { id: bodyId, hidden: isCollapsed }, [
                el('p', { 'class': 'mai-gist', text: s.gist }),
                (s.key_points && s.key_points.length) || (s.asked_of_you && s.asked_of_you.length)
                    ? el('div', { 'class': 'mai-cols' }, [list('Points clés', s.key_points), list('En attente de notre part', s.asked_of_you)])
                    : null,
                el('p', { 'class': 'mai-foot', text: foot })
            ]);
            toggle.addEventListener('click', function () {
                collapsed[threadId] = !collapsed[threadId];
                body.hidden = collapsed[threadId];
                toggle.setAttribute('aria-expanded', collapsed[threadId] ? 'false' : 'true');
                var label = collapsed[threadId] ? 'Afficher le résumé' : 'Masquer le résumé';
                toggle.setAttribute('aria-label', label);
                toggle.title = label;
            });
            sumRoot.appendChild(el('section', { 'class': 'mai-card', 'aria-labelledby': 'mai-sum-h-' + threadId }, [
                el('div', { 'class': 'mai-card-head' }, [el('h3', { id: 'mai-sum-h-' + threadId, icon: 'sparkles' }, ['Résumé']), toggle]),
                body
            ]));
            sumRoot.hidden = false;
            return;
        }
        if (!data.can_summarise) {
            sumRoot.hidden = true;
            return;
        }
        var button = el('button', { type: 'button', 'class': 'mb-btn mb-btn--sm mai-run-btn', icon: 'sparkles' }, ['Résumer']);
        var busy = el('span', { 'class': 'mai-busy', role: 'status', hidden: true }, [el('span', { 'class': 'mai-spinner', 'aria-hidden': 'true' }), ' Praxis AI lit la conversation…']);
        var error = el('p', { 'class': 'mai-error', role: 'alert', hidden: true });
        button.addEventListener('click', function () {
            var seq = sumSeq;
            button.hidden = true;
            busy.hidden = false;
            error.hidden = true;
            api('POST', { action: 'summarise', thread: threadId }).then(function (json) {
                if (seq !== sumSeq) return;
                renderSummary(threadId, json.data || {});
                emit('mail:thread-changed', { threadId: threadId, source: 'ai' });
            }, function (err) {
                if (seq !== sumSeq) return;
                button.hidden = false;
                busy.hidden = true;
                error.textContent = err.message;
                error.hidden = false;
            });
        });
        sumRoot.appendChild(el('div', { 'class': 'mai-run' }, [button, busy, error]));
        sumRoot.hidden = false;
    }

    // ================================================================== Demander à votre courrier

    var askDlg = prepareDialog(document.getElementById('mai-ask'));
    var ask = askDlg ? {
        q: askDlg.querySelector('#mai-question'),
        scope: askDlg.querySelector('#mai-scope'),
        scopeBox: askDlg.querySelector('[data-mai-scope-box]'),
        submit: askDlg.querySelector('[data-mai-ask-submit]'),
        examples: askDlg.querySelector('[data-mai-ask-examples]'),
        asked: askDlg.querySelector('[data-mai-asked]'),
        answer: askDlg.querySelector('[data-mai-answer]'),
        sources: askDlg.querySelector('[data-mai-sources]'),
        sourcesList: askDlg.querySelector('[data-mai-sources-list]'),
        caution: askDlg.querySelector('[data-mai-caution]'),
        error: askDlg.querySelector('[data-mai-ask-error]'),
        busy: askDlg.querySelector('[data-mai-ask-busy]'),
        form: askDlg.querySelector('[data-mai-ask-form]'),
        running: false
    } : null;

    function updateScope() {
        if (!ask || !ask.scopeBox) return;
        var email = state.accountId ? boxes[state.accountId] : null;
        ask.scopeBox.textContent = email ? email + ' seulement' : 'Cette boîte seulement';
        ask.scopeBox.hidden = !email;
        ask.scopeBox.disabled = !email;
        if (!email) ask.scope.value = 'all';
    }

    function askNow() {
        if (!ask || ask.running) return;
        var question = ask.q.value.trim();
        fieldError(ask.form, 'question', '');
        ask.error.hidden = true;
        if (question.length < 3) {
            fieldError(ask.form, 'question', 'Écrivez votre question, par exemple avec un nom ou un sujet.');
            return;
        }
        ask.running = true;
        ask.submit.disabled = true;
        ask.examples.hidden = true;
        ask.answer.hidden = true;
        ask.sources.hidden = true;
        ask.caution.hidden = true;
        ask.asked.textContent = question;
        ask.asked.hidden = false;
        ask.busy.hidden = false;
        api('POST', {
            action: 'ask', question: question,
            scope: ask.scope && ask.scope.value === 'box' ? 'box' : 'all',
            box: state.accountId
        }).then(function (json) {
            var d = json.data || {};
            ask.answer.innerHTML = d.html || '';   // escaped by the server (MailAsk::answerHtml): only <p>, <ul>, <li>, <a class="mai-cite">
            ask.answer.hidden = false;
            ask.sourcesList.textContent = '';
            (d.sources || []).forEach(function (s) {
                ask.sourcesList.appendChild(el('li', {}, [el('a', { href: s.url }, [
                    el('span', { 'class': 'mai-n', text: String(s.n) }),
                    el('span', { 'class': 'mai-src' }, [
                        el('strong', { text: s.subject }),
                        el('span', { text: s.from + (s.date_label ? ' · ' + s.date_label : '') })
                    ])
                ])]));
            });
            ask.sources.hidden = !(d.sources && d.sources.length);
            ask.caution.hidden = ask.sources.hidden;
            ask.q.value = '';
        }, function (err) {
            if (err.field === 'question') {
                fieldError(ask.form, 'question', err.message);
            } else {
                ask.error.textContent = err.message;
                ask.error.hidden = false;
            }
        }).then(function () {
            ask.running = false;
            ask.submit.disabled = false;
            ask.busy.hidden = true;
        });
    }

    if (ask) {
        onEnter(ask.q, askNow);
        ask.submit.addEventListener('click', askNow);
        ask.q.addEventListener('input', function () { fieldError(ask.form, 'question', ''); });
        askDlg.addEventListener('click', function (ev) {
            var ex = ev.target.closest('[data-mai-example]');
            if (ex) {
                ask.q.value = ex.textContent.trim();
                askNow();
            }
        });
        updateScope();
    }

    // ================================================================== Écrire avec l'IA

    var writeDlg = prepareDialog(document.getElementById('mai-write'));
    var write = writeDlg ? {
        hint: writeDlg.querySelector('[data-mai-write-hint]'),
        instr: writeDlg.querySelector('#mai-instruction'),
        instrHint: writeDlg.querySelector('#mai-instruction-hint'),
        busy: writeDlg.querySelector('[data-mai-write-busy]'),
        draft: writeDlg.querySelector('[data-mai-draft]'),
        draftText: writeDlg.querySelector('[data-mai-draft-text]'),
        submit: writeDlg.querySelector('[data-mai-write-submit]'),
        retry: writeDlg.querySelector('[data-mai-write-retry]'),
        use: writeDlg.querySelector('[data-mai-write-use]'),
        form: writeDlg.querySelector('[data-mai-write-form]'),
        error: null,
        result: null,
        seq: 0
    } : null;

    function composer() {
        var c = window.LPCMailComposer;
        try { return c && typeof c.current === 'function' ? c.current() : null; } catch (e) { return null; }
    }

    function textOf(html) {
        if (!html) return '';
        var doc = new DOMParser().parseFromString(String(html).replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|li|h[1-6])>/gi, '\n'), 'text/html');
        return (doc.body.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
    }

    function resetWrite() {
        if (!write) return;
        write.seq++;
        write.result = null;
        write.instr.value = '';
        write.draft.hidden = true;
        write.draftText.textContent = '';
        write.busy.hidden = true;
        write.submit.hidden = false;
        write.submit.disabled = false;
        write.retry.hidden = true;
        write.retry.disabled = false;
        write.use.hidden = true;
        fieldError(write.form, 'instruction', '');
    }

    function runWrite() {
        var c = composer();
        if (!c) {
            fieldError(write.form, 'instruction', 'Le message a été fermé. Rouvrez-le, puis réessayez.');
            return;
        }
        var seq = ++write.seq;
        fieldError(write.form, 'instruction', '');
        write.busy.hidden = false;
        write.submit.disabled = true;
        write.retry.disabled = true;
        write.use.hidden = true;
        api('POST', {
            action: 'write',
            instruction: write.instr.value,
            composer: {
                accountId: c.accountId, mode: c.mode, messageId: c.messageId || null, outboxId: c.outboxId || null,
                subject: c.subject || '', text: c.text || textOf(c.html), to: c.to || null
            }
        }).then(function (json) {
            if (seq !== write.seq) return;
            write.result = json.data || null;
            write.draftText.textContent = (write.result && write.result.text) || '';
            write.draft.hidden = false;
            write.submit.hidden = true;
            write.retry.hidden = false;
            write.use.hidden = false;
            write.use.focus();
        }, function (err) {
            if (seq !== write.seq) return;
            fieldError(write.form, 'instruction', err.message);
        }).then(function () {
            if (seq !== write.seq) return;
            write.busy.hidden = true;
            write.submit.disabled = false;
            write.retry.disabled = false;
        });
    }

    if (write) {
        write.submit.addEventListener('click', runWrite);
        write.retry.addEventListener('click', runWrite);
        onEnter(write.instr, runWrite);
        write.instr.addEventListener('input', function () { fieldError(write.form, 'instruction', ''); });
        write.use.addEventListener('click', function () {
            var c = window.LPCMailComposer;
            if (!write.result || !c || typeof c.replaceBody !== 'function' || !composer()) {
                fieldError(write.form, 'instruction', 'Le message a été fermé. Rouvrez-le, puis réessayez.');
                return;
            }
            c.replaceBody(write.result.html);
            writeDlg.close();
            resetWrite();
            toast('Brouillon placé dans le message. Relisez-le avant d’envoyer.', 'success');
        });
    }

    function openWrite(opener) {
        var c = composer();
        if (!c) {
            toast('Ouvrez d’abord un message.', 'warning');
            return;
        }
        var mode = c.mode || 'new';
        var wrote = !!(c.text || textOf(c.html));
        var hint;
        if (mode === 'reply' || mode === 'reply_all') {
            hint = 'Praxis AI lit la conversation (ses 30 derniers messages, sans les pièces jointes) et propose une réponse.';
            write.instrHint.textContent = 'Facultatif pour une réponse. Par exemple : accepter et proposer jeudi à 10 h.';
        } else if (mode === 'forward') {
            hint = 'Praxis AI lit le message transféré et propose une courte note à placer au-dessus.';
            write.instrHint.textContent = 'Facultatif. Par exemple : demander à Paul de traiter la commande avant vendredi.';
        } else {
            hint = 'Dites ce que le message doit dire : Praxis AI propose un brouillon.';
            write.instrHint.textContent = 'Par exemple : remercier pour la commande et proposer une livraison jeudi matin.';
        }
        write.hint.textContent = hint + (wrote ? ' Il part aussi de ce que vous avez déjà écrit.' : '') + ' Vous le relisez avant de l’utiliser.';
        write.use.textContent = wrote ? 'Remplacer mon texte' : 'Utiliser ce brouillon';
        openDialog(writeDlg, opener, write.instr);
    }

    // ================================================================== page events

    document.addEventListener('click', function (ev) {
        var opener = ev.target.closest('[data-mai-ask-open]');
        if (opener && askDlg) {
            updateScope();
            openDialog(askDlg, opener, ask.q);
            return;
        }
        opener = ev.target.closest('[data-mai-write-open]');
        if (opener && writeDlg) {
            ev.preventDefault();
            openWrite(opener);
        }
    });

    document.addEventListener('mail:thread-opened', function (ev) {
        var d = ev.detail || {};
        if (d.accountId) state.accountId = Number(d.accountId);
        state.folderRole = d.folderRole || 'inbox';
        updateScope();
        summaryFor(d.threadId ? Number(d.threadId) : null, state.folderRole);
    });

    document.addEventListener('mail:refresh', function (ev) {
        var d = ev.detail || {};
        if (d.accountId) {
            state.accountId = Number(d.accountId);
            updateScope();
        }
        // New mail may have cleared the summary of the conversation on screen
        if (state.threadId) summaryFor(state.threadId, state.folderRole);
    });

    document.addEventListener('mail:thread-changed', function (ev) {
        var d = ev.detail || {};
        if (d.source !== 'ai' && state.threadId && Number(d.threadId) === state.threadId) summaryFor(state.threadId, state.folderRole);
    });

    document.addEventListener('mail:compose-opened', resetWrite);
    document.addEventListener('mail:compose-closed', function () {
        if (writeDlg && writeDlg.open) writeDlg.close();
        resetWrite();
    });

    if (DATA.threadId) summaryFor(Number(DATA.threadId), state.folderRole);
})();
