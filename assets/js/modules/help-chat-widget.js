/**
 * assets/js/modules/help-chat-widget.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — floating widget.
 *
 * Owns: open/close, sending a question to api/v1/help_chat_controller.php
 * (action=ask), and rendering the reply. Markup lives in
 * includes/components/help_chat_widget.php; CSS in assets/css/lpc-help.css §6.
 *
 * answer_html in the response is already server-rendered by lpc_help_markdown()
 * — escaped first, then a small tag set whitelisted back in (see that
 * function's header in includes/functions/help.php). It is inserted with
 * innerHTML here exactly the way modules/help/article.php inserts
 * $article['body_html'] — same trust boundary, same reason it's safe.
 *
 * Transport idiom copied from lpc-usermenu.js / help-ai-settings.js: FormData,
 * CSRF token as both a field and a header.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var ENDPOINT  = '/api/v1/help_chat_controller.php';
    var launcher  = document.getElementById('lpc-ai-chat-launcher');
    var panel     = document.getElementById('lpc-ai-chat-panel');
    if (!launcher || !panel) return;

    var closeBtn  = document.getElementById('lpc-ai-chat-close');
    var messages  = document.getElementById('lpc-ai-chat-messages');
    var form      = document.getElementById('lpc-ai-chat-form');
    var input     = document.getElementById('lpc-ai-chat-input');
    var quotaEl   = document.getElementById('lpc-ai-chat-quota');
    var lang      = panel.getAttribute('data-lang') === 'en' ? 'en' : 'fr';
    var t = {
        thinking:     lang === 'en' ? 'Thinking…'                                  : 'Recherche en cours…',
        networkError: lang === 'en' ? 'Connection failed. Check your network.'     : 'Connexion impossible. Vérifiez votre réseau.',
        sessionDead:  lang === 'en' ? 'Session expired. Reload the page.'          : 'Session expirée. Rechargez la page.',
        sourcesLabel: lang === 'en' ? 'Sources'                                    : 'Sources',
        goToPage:     lang === 'en' ? 'Go to this page'                           : 'Aller à cette page',
        quotaLeft:    lang === 'en' ? '{n} of {cap} questions left today'          : "{n} question(s) restantes aujourd'hui sur {cap}",
    };

    // ---------------------------------------------------------- Quota ----
    // Shows "N of CAP questions left today" in the panel head, so a user
    // sees the limit BEFORE they run out instead of discovering it via a
    // quota_exceeded reply. Fetched lazily (only once, on first open — no
    // reason to spend a request on every page load for a widget the user
    // may never open) and then kept in sync from each ask() response's own
    // `quota` field, which is already the freshest possible number since it
    // reflects the request that just ran.
    var quotaFetched = false;

    function renderQuota(q) {
        if (!quotaEl || !q || q.cap === null || q.cap === undefined) {
            // Unlimited role (cap === null) or no data yet — nothing to show.
            if (quotaEl) quotaEl.hidden = true;
            return;
        }
        var remaining = q.remaining;
        quotaEl.textContent = t.quotaLeft.replace('{n}', remaining).replace('{cap}', q.cap);
        quotaEl.classList.toggle('lpc-ai-chat-quota--low', q.cap > 0 && remaining <= Math.ceil(q.cap * 0.2));
        quotaEl.hidden = false;
    }

    function fetchQuota() {
        if (quotaFetched) return;
        quotaFetched = true;
        var c  = csrf();
        var fd = new FormData();
        fd.append('action', 'quota');
        if (c.token) fd.append(c.field || '_csrf', c.token);
        fetch(ENDPOINT, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) { if (json && json.status === 'success') renderQuota(json); })
            .catch(function () { /* silent — the indicator just stays hidden */ });
    }

    // ------------------------------------------------------- Transcript ----
    // Persisted to sessionStorage (this browser tab only, cleared when it's
    // closed — not localStorage, which would leak one user's chat into
    // whoever's session opens the ERP in that browser next). Purpose: a
    // question's answer often links the user AWAY to the actual ERP page
    // (see the page_path source link below) — when they come back to the
    // help centre, the conversation should still be there instead of
    // resetting to the empty greeting every time they navigate.
    var HISTORY_KEY  = 'lpc_ai_chat_history_v1';
    var HISTORY_CAP  = 40;   // ~20 exchanges; bounds storage size, not a UX limit

    function loadHistory() {
        try {
            var raw = window.sessionStorage.getItem(HISTORY_KEY);
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];   // storage disabled/full/corrupt — degrade to no persistence
        }
    }

    function saveHistory(arr) {
        try {
            window.sessionStorage.setItem(HISTORY_KEY, JSON.stringify(arr.slice(-HISTORY_CAP)));
        } catch (e) {
            // Quota exceeded or storage disabled — the chat still works this
            // page load, it just won't survive navigation. Not worth surfacing.
        }
    }

    var history = loadHistory();

    function pushHistory(entry) {
        history.push(entry);
        saveHistory(history);
    }

    var open = false;

    function setOpen(next) {
        open = next;
        panel.hidden = !open;
        launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        launcher.classList.toggle('is-open', open);
        if (open) {
            window.setTimeout(function () { input && input.focus(); }, 50);
            fetchQuota();
        }
    }

    launcher.addEventListener('click', function () { setOpen(!open); });
    if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && open) setOpen(false);
    });

    // ---------------------------------------------------------------- CSRF --
    function csrf() {
        var c = (window.LPC && window.LPC.rbac && window.LPC.rbac.csrf) || null;
        return c || { token: '', header: 'X-CSRF-Token', field: '_csrf' };
    }

    function ask(question) {
        var c  = csrf();
        var fd = new FormData();
        fd.append('action', 'ask');
        fd.append('question', question);
        fd.append('lang', lang);
        fd.append('page_path', window.location.pathname);
        if (c.token) fd.append(c.field || '_csrf', c.token);

        var headers = {};
        if (c.token) headers[c.header || 'X-CSRF-Token'] = c.token;

        return fetch(ENDPOINT, { method: 'POST', body: fd, headers: headers, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401) return { status: 'error', message: t.sessionDead };
                return r.json().catch(function () { return { status: 'error', message: t.networkError }; });
            })
            .catch(function () { return { status: 'error', message: t.networkError }; });
    }

    // ------------------------------------------------------------ Rendering --
    function scrollToEnd() { messages.scrollTop = messages.scrollHeight; }

    function addUserMessage(text) {
        var div = document.createElement('div');
        div.className = 'lpc-ai-chat-msg lpc-ai-chat-msg--user';
        var bubble = document.createElement('div');
        bubble.className = 'lpc-ai-chat-bubble';
        bubble.textContent = text;   // plain text — never HTML for the user's own input
        div.appendChild(bubble);
        messages.appendChild(div);
        scrollToEnd();
        return div;
    }

    function addBotPlaceholder(text) {
        var div = document.createElement('div');
        div.className = 'lpc-ai-chat-msg lpc-ai-chat-msg--bot lpc-ai-chat-msg--pending';
        div.innerHTML = '<div class="lpc-ai-chat-bubble"><span class="lpc-ai-chat-dots" aria-hidden="true"><i></i><i></i><i></i></span> '
            + '<span class="lpc-ai-chat-pending-label"></span></div>';
        div.querySelector('.lpc-ai-chat-pending-label').textContent = text;
        messages.appendChild(div);
        scrollToEnd();
        return div;
    }

    function renderBotAnswer(placeholderEl, json) {
        placeholderEl.classList.remove('lpc-ai-chat-msg--pending');
        var bubble = document.createElement('div');
        // lpc-help-prose is the SAME class the full article body renders
        // inside (see lpc-help.css §4) — reused here rather than duplicated
        // so headings/lists/links inside an answer get identical treatment
        // for free, and stay in sync if that styling ever changes.
        bubble.className = 'lpc-ai-chat-bubble lpc-help-prose';

        if (json.status === 'error') {
            bubble.innerHTML = '<p class="lpc-ai-chat-error">' + escapeHtml(json.message || t.networkError) + '</p>';
        } else if (json.configured === false || json.quota_exceeded === true) {
            // Both are plain informational states, not errors: the assistant
            // isn't set up yet, or this user has hit their daily cap. Neither
            // ships an answer_html, just a message.
            bubble.innerHTML = '<p>' + escapeHtml(json.message || '') + '</p>';
        } else {
            bubble.innerHTML = json.answer_html || '';
            if (json.sources && json.sources.length) {
                var srcWrap = document.createElement('div');
                srcWrap.className = 'lpc-ai-chat-sources';
                var label = document.createElement('p');
                label.className = 'lpc-ai-chat-sources-label';
                label.textContent = t.sourcesLabel;
                srcWrap.appendChild(label);
                json.sources.forEach(function (s) {
                    var row = document.createElement('div');
                    row.className = 'lpc-ai-chat-source-row';

                    var a = document.createElement('a');
                    a.href = s.url;
                    a.className = 'lpc-ai-chat-source';
                    a.textContent = s.title;
                    row.appendChild(a);

                    // page_path is only set when the article is bound to an
                    // actual ERP screen (help_articles.page_path) — most
                    // useful link in the whole answer when present, since it
                    // takes the user straight to the feature instead of more
                    // reading. Absent for purely conceptual articles.
                    if (s.page_path) {
                        var p = document.createElement('a');
                        p.href = s.page_path;
                        p.target = '_blank';
                        p.rel = 'noopener noreferrer';
                        p.className = 'lpc-ai-chat-source lpc-ai-chat-source--page';
                        p.textContent = '→ ' + t.goToPage;
                        row.appendChild(p);
                    }

                    srcWrap.appendChild(row);
                });
                bubble.appendChild(srcWrap);
            }
        }

        placeholderEl.innerHTML = '';
        placeholderEl.appendChild(bubble);
        scrollToEnd();
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    // ------------------------------------------------------------- Submit --
    var sending = false;

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (sending) return;

        var question = (input.value || '').trim();
        if (!question) return;

        sending = true;
        input.disabled = true;
        addUserMessage(question);
        input.value = '';
        autoResize();
        var placeholder = addBotPlaceholder(t.thinking);

        ask(question).then(function (json) {
            renderBotAnswer(placeholder, json);
            if (json.quota) { quotaFetched = true; renderQuota(json.quota); }
            // Persist AFTER the outcome is known, and as a matched pair —
            // never a saved question with no saved answer, even if the tab
            // is closed mid-request (nothing gets pushed in that case, which
            // is correct: there's nothing complete to restore).
            pushHistory({ role: 'user', text: question });
            pushHistory({ role: 'bot', json: json });
            sending = false;
            input.disabled = false;
            input.focus();
        });
    });

    // Enter sends, Shift+Enter inserts a newline.
    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) {
            ev.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    // Cheap auto-grow textarea, capped so the panel doesn't get pushed off-screen.
    function autoResize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }
    input.addEventListener('input', autoResize);

    // --------------------------------------------------------- Hydration ----
    // Replay any conversation saved earlier in this tab's session, on top of
    // the static greeting bubble already in the markup — so a user who
    // followed a "go to this page" link away and came back (or just
    // navigated to another help centre page) finds their chat as they left
    // it instead of starting over. Synchronous, no network calls: every
    // saved bot entry is the exact JSON api/v1/help_chat_controller.php
    // returned the first time, replayed through the same renderBotAnswer()
    // used for a live answer.
    (function hydrate() {
        history.forEach(function (entry) {
            if (entry.role === 'user' && typeof entry.text === 'string') {
                addUserMessage(entry.text);
            } else if (entry.role === 'bot' && entry.json) {
                renderBotAnswer(addBotPlaceholder(''), entry.json);
            }
        });
    })();
})();
