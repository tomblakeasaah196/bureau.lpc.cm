<?php
/**
 * modules/mail/mounts/thread_team.php — mount point stub (docs/MAIL.md §6.2).
 * -----------------------------------------------------------------------------
 * Filled by PR 4 (teamwork), which replaces this file. Until then it prints nothing,
 * so the pages that include it work as they are.
 *
 * The team bar of a shared mailbox's conversation: assignee, Open / Done, internal notes, who is viewing or replying, activity. Follows mail:thread-opened.
 *
 * In scope: $mailMount (array: account_id, folder, thread_id, user_id as the
 * page knows them). Included through \LPC\Mail\Mount::render('thread_team', [...]).
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}
