<?php
/**
 * modules/mail/mounts/thread_team.php — the team bar (Courrier PR 4; docs/MAIL.md §6.2).
 * -----------------------------------------------------------------------------
 * In the reader's header, for conversations of shared mailboxes: Ouverte /
 * Traitée, who it is assigned to, internal notes, who else is viewing or
 * replying, and the activity timeline. Prints one empty, hidden container;
 * assets/js/modules/mail-team.js fills it from api/v1/mail/team.php when
 * mail:thread-opened says a shared conversation is on screen, and keeps it up
 * to date (every 20 s while the tab is visible and the person active). On a
 * personal mailbox's conversation it stays hidden, so mounting it always is
 * fine.
 *
 * In scope: $mailMount (array: user_id, account_id, folder, thread_id, shared).
 * Included through \LPC\Mail\Mount::render('thread_team', [...]), once per page.
 * -----------------------------------------------------------------------------
 */

if (!isset($mailMount)) {   // reached by URL, not through Mount::render()
    http_response_code(404);
    return;
}
if (defined('LPC_MAIL_TEAM_MOUNTED')) {   // the container is printed once per page
    return;
}
define('LPC_MAIL_TEAM_MOUNTED', true);

if (!\LPC\Mail\MailAccess::canUse((int) ($mailMount['user_id'] ?? 0))) {
    return;
}
?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail-team.css') ?>">
<section class="mtw" data-mail-team hidden aria-label="Travail en équipe sur cette conversation"
         data-api="/api/v1/mail/team.php"
         data-team-thread="<?= (int) ($mailMount['thread_id'] ?? 0) ?>"
         data-team-shared="<?= !empty($mailMount['shared']) ? '1' : '0' ?>"></section>
<script src="<?= lpc_asset('/assets/js/modules/mail-team.js') ?>" defer></script>
