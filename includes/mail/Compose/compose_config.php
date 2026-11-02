<?php
/**
 * includes/mail/Compose/compose_config.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 3 (writing and signatures): the composer's and the outbox's
 * settings, read through LPC\Mail\Compose\ComposeConfig::get('undo_seconds').
 * PR 1's includes/mail/mail_config.php stays PR 1's (docs/MAIL.md §5).
 * -----------------------------------------------------------------------------
 */

$env = static function (string $key, $default) {
    return function_exists('env') ? env($key, $default) : $default;
};

return [

    // "Annuler l'envoi": a message waits this long before it goes
    'undo_seconds' => 10,

    // One message goes to at most this many people (To, Cc and Bcc together)
    'max_recipients' => 100,

    // Files on one message, all together (bytes). Base64 adds a third on the way, and many mail servers
    // (Gmail among them) refuse messages over 25 MB, so 18 MB of files is the most that arrives everywhere.
    'max_attachment_bytes' => 18 * 1024 * 1024,

    // Uploaded files are kept in the database in pieces of this size (mail_compose_files)
    'chunk_bytes' => 512 * 1024,

    // A temporary SMTP failure is tried again after these many minutes, then the message is "not sent"
    'retry_minutes' => [1, 2, 5, 15, 30, 60],

    // A row left in "sending" this long (a run that stopped) is marked not sent; never sent again by itself
    'stuck_minutes' => 15,

    // Rows sent per run of scripts/cron/mail_send_due.php (it runs every minute)
    'batch' => 25,

    // While a person types, the copy in the server's Drafts is refreshed at most this often (seconds)
    'server_draft_seconds' => 60,

    // Drafts opened and never written in are removed after this many hours
    'untouched_hours' => 24,

    // Address suggestions shown while typing
    'suggestions' => 8,

    // The signature's logo, as recipients' mail apps load it: an absolute https address on bureau's own host
    // (mail apps never show SVG, so a PNG; 240 px for an 80 px picture, sharp on phones). Admins may set
    // another https address on Ma signature (mail_settings signature.company.logo_url).
    'logo_url' => (string) $env('MAIL_SIGNATURE_LOGO_URL', rtrim((string) $env('APP_URL', 'https://bureau.lpc.cm'), '/') . '/assets/img/mail/lpc-signature-logo.png'),
];
