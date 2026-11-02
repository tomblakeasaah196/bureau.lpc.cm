<?php
/**
 * modules/mail/mounts/thread_summary.php — mount point stub (docs/MAIL.md §6.2).
 * -----------------------------------------------------------------------------
 * Filled by PR 5 (Praxis AI), which replaces this file. Until then it prints nothing,
 * so the pages that include it work as they are.
 *
 * Summarise: the button and the summary card above the messages (never in Spam). Follows mail:thread-opened.
 *
 * In scope: $mailMount (array: account_id, folder, thread_id, user_id as the
 * page knows them). Included through \LPC\Mail\Mount::render('thread_summary', [...]).
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}
