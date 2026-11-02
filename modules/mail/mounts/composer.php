<?php
/**
 * modules/mail/mounts/composer.php — mount point stub (docs/MAIL.md §6.2).
 * -----------------------------------------------------------------------------
 * Filled by PR 3 (writing), which replaces this file. Until then it prints nothing,
 * so the pages that include it work as they are.
 *
 * The composer: new, reply, reply all, forward, drafts, send, undo, schedule. Prints its dialog once and loads assets/js/modules/mail-compose.js, which opens on the browser event mail:compose.
 *
 * In scope: $mailMount (array: account_id, folder, thread_id, user_id as the
 * page knows them). Included through \LPC\Mail\Mount::render('composer', [...]).
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}
