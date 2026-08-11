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
    var lang      = panel.getAttribute('data-lang') === 'en' ? 'en' : 'fr';
    var t = {
        thinking:     lang === 'en' ? 'Thinking…'                                  : 'Recherche en cours…',
        networkError: lang === 'en' ? 'Connection failed. Check your network.'     : 'Connexion impossible. Vérifiez votre réseau.',
        sessionDead:  lang === 'en' ? 'Session expired. Reload the page.'          : 'Session expirée. Rechargez la page.',
        sourcesLabel: lang === 'en' ? 'Sources'                                    : 'Sources',
    };

    var open = false;

    function setOpen(next) {
        open = next;
        panel.hidden = !open;
        launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        launcher.classList.toggle('is-open', open);
        if (open) {
            window.setTimeout(function () { input && input.focus(); }, 50);
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
                    var a = document.createElement('a');
                    a.href = s.url;
                    a.className = 'lpc-ai-chat-source';
                    a.textContent = s.title;
                    srcWrap.appendChild(a);
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
})();
