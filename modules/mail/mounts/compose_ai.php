<?php
/**
 * modules/mail/mounts/compose_ai.php — mount point stub (docs/MAIL.md §6.2).
 * -----------------------------------------------------------------------------
 * Filled by PR 5 (Praxis AI), which replaces this file. Until then it prints nothing,
 * so the pages that include it work as they are.
 *
 * Write with AI inside the composer's toolbar. PR 3's composer includes this mount; the button talks to the composer through window.LPCMailComposer (docs/MAIL.md §6.3).
 *
 * In scope: $mailMount (array: account_id, folder, thread_id, user_id as the
 * page knows them). Included through \LPC\Mail\Mount::render('compose_ai', [...]).
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}
