<?php
/**
 * includes/mail/Team/team_config.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier PR 4: teamwork on shared mailboxes and new-mail
 * alerts (docs/MAIL.md §2, §7.5). Read through LPC\Mail\Team\MailTeam::config();
 * tests may override with MailTeam::configure().
 * -----------------------------------------------------------------------------
 */

return [
    // A viewing / replying mark lasts this long unless the screen renews it
    'presence_seconds' => 60,

    // How often an open conversation renews its mark and picks up the team's changes (mail-team.js)
    'poll_seconds' => 20,

    // Longest internal note, in characters
    'note_max' => 5000,

    // Lines shown in the activity timeline, newest first
    'activity_limit' => 30,

    // New-mail alerts: the message line is the subject, else this many first words of the text
    'alert_words' => 12,
];
