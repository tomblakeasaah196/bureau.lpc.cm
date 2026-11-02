<?php
/**
 * modules/mail/mounts/thread_summary.php — Résumer (PR 5; docs/MAIL.md §6.2, §7.6).
 * -----------------------------------------------------------------------------
 * At the top of the reader, above the messages: the "Résumer" button, then the
 * summary card (gist, points clés, ce qu'on attend de nous). The summary is kept
 * on the conversation for everyone who reads it until a new message arrives
 * (sync clears it). Never in Indésirables or Corbeille: the card hides there.
 *
 * Prints an empty container; assets/js/modules/mail-ai.js fills it for the
 * conversation on screen (mail:thread-opened, or the page's ?thread=) from
 * api/v1/mail/ai.php. Nothing shows until the owner has entered the Gemini key.
 *
 * In scope: $mailMount (user_id, account_id, folder, thread_id, shared), through
 * \LPC\Mail\Mount::render('thread_summary', $mailMount).
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}

if (!\LPC\Mail\Ai\Ui::enabled((int) ($mailMount['user_id'] ?? 0))) {
    return;
}
\LPC\Mail\Ai\Ui::assets($mailMount);
?>
<div class="mai-summary" data-mai-summary hidden></div>
