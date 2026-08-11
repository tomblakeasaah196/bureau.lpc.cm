<?php
/**
 * includes/components/help_chat_widget.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — floating widget.
 *
 * Included from modules/help/index.php and modules/help/article.php only, for
 * now — the help centre's own pages, not the whole ERP shell. Smaller blast
 * radius for a first ship; promoting it to every page (via the shared shell,
 * like the sidebar/topbar) is a natural follow-up once it's proven out here.
 *
 * Pure markup + a mount point. assets/js/modules/help-chat-widget.js owns all
 * behaviour (open/close, the ask() call, rendering). CSS lives in
 * assets/css/lpc-help.css §6, alongside the rest of the help centre's chrome
 * — that file already loads on every page (see head_assets.php), so nothing
 * new needs linking here.
 *
 * Expects $lang / $en to already be set by the including page (both
 * modules/help/index.php and article.php compute them before requiring
 * this file) — a PHP include shares the caller's scope, so no re-fetch.
 * -----------------------------------------------------------------------------
 */
if (!defined('LPC_BOOTSTRAPPED')) { return; }

$lang = $lang ?? 'fr';
$en   = $en   ?? ($lang === 'en');
?>
<button type="button" id="lpc-ai-chat-launcher" class="lpc-ai-chat-launcher"
        aria-haspopup="dialog" aria-expanded="false" aria-controls="lpc-ai-chat-panel"
        title="<?= $en ? 'Ask Praxis' : 'Demander à Praxis' ?>">
    <svg class="lpc-ai-chat-launcher-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm3.75 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm3.75 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/>
    </svg>
    <svg class="lpc-ai-chat-launcher-icon lpc-ai-chat-launcher-icon--close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/>
    </svg>
</button>

<div id="lpc-ai-chat-panel" class="lpc-ai-chat-panel" hidden role="dialog" aria-modal="false"
     aria-label="Praxis" data-lang="<?= $lang ?>">

    <div class="lpc-ai-chat-head">
        <div>
            <p class="lpc-ai-chat-eyebrow"><?= $en ? 'PRAXIS · HELP CENTRE ASSISTANT' : "PRAXIS · ASSISTANT DU CENTRE D'AIDE" ?></p>
            <p class="lpc-ai-chat-title"><?= $en ? 'Ask a question' : 'Posez une question' ?></p>
            <!-- Populated by help-chat-widget.js from action=quota, lazily on
                 first open — empty/hidden until then, and left empty forever
                 for an unlimited role (daily_limit = NULL), so most admins
                 never see it at all. -->
            <p id="lpc-ai-chat-quota" class="lpc-ai-chat-quota" hidden></p>
        </div>
        <button type="button" id="lpc-ai-chat-close" class="lpc-ai-chat-close"
                aria-label="<?= $en ? 'Close' : 'Fermer' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div id="lpc-ai-chat-messages" class="lpc-ai-chat-messages" aria-live="polite">
        <div class="lpc-ai-chat-msg lpc-ai-chat-msg--bot">
            <div class="lpc-ai-chat-bubble">
                <p><?= $en
                    ? "Hi, I'm Praxis. Ask me anything about the ERP — I'll search the help articles you have access to and answer from those, with links to the sources and, where relevant, straight to the page itself."
                    : "Bonjour, je suis Praxis. Posez-moi une question sur l'ERP — je chercherai dans les articles d'aide auxquels vous avez accès et je répondrai à partir de ceux-ci, avec des liens vers les sources et, si utile, directement vers la page concernée." ?></p>
            </div>
        </div>
    </div>

    <form id="lpc-ai-chat-form" class="lpc-ai-chat-form">
        <?= Csrf::field() ?>
        <textarea id="lpc-ai-chat-input" name="question" rows="1" maxlength="800"
                  placeholder="<?= $en ? 'Type your question…' : 'Tapez votre question…' ?>"
                  aria-label="<?= $en ? 'Your question' : 'Votre question' ?>"></textarea>
        <button type="submit" class="lpc-ai-chat-send" aria-label="<?= $en ? 'Send' : 'Envoyer' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
        </button>
    </form>
</div>
