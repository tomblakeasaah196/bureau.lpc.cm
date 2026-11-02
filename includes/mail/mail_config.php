<?php
/**
 * includes/mail/mail_config.php
 * -----------------------------------------------------------------------------
 * Courrier (Mail) settings, read through LPC\Mail\Config::get('sync.months').
 * Not to be confused with includes/classes/Mail.php, which is how bureau itself
 * sends system email (password resets) through the server's exim.
 *
 * PR 1 owns this file. PRs 2 to 5 keep their own settings in their own config
 * file (includes/mail/Read/read_config.php, …) or in mail_settings (docs/MAIL.md §5).
 * -----------------------------------------------------------------------------
 */

$env = static function (string $key, $default) {
    return function_exists('env') ? env($key, $default) : $default;
};

return [

    // Server settings by address domain (cPanel, "Configuration manuelle du client
    // de messagerie" for lpc.cm). Connecting fills these in; a person can change them
    // under "Paramètres du serveur". Other domains use mail.<domain> and the defaults.
    'servers' => [
        'lpc.cm' => [
            'imap_host'       => $env('MAIL_CLIENT_IMAP_HOST', 'mail.lpc.cm'),
            'imap_port'       => 993,
            'imap_encryption' => 'ssl',
            'smtp_host'       => $env('MAIL_CLIENT_SMTP_HOST', 'mail.lpc.cm'),
            'smtp_port'       => 465,
            'smtp_encryption' => 'ssl',
        ],
    ],

    'default_server' => [
        'imap_port'       => 993,
        'imap_encryption' => 'ssl',
        'smtp_port'       => 465,
        'smtp_encryption' => 'ssl',
    ],

    // Mail ports only: bureau must not become a way to probe other services
    'imap_ports' => [993, 143],
    'smtp_ports' => [465, 587, 25, 2525],

    // Which servers bureau may connect to (LPC\Mail\HostPolicy, docs/MAIL.md §7.1): the hosts of 'servers'
    // above plus these (an owner's decision, in .env: MAIL_CLIENT_ALLOWED_HOSTS=mail.other.cm,imap.x.cm)
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) $env('MAIL_CLIENT_ALLOWED_HOSTS', ''))))),
    // Local tests only, never on live: IP literals and hosts resolving to internal addresses
    'allow_private_hosts' => filter_var($env('MAIL_CLIENT_ALLOW_PRIVATE_HOSTS', 'false'), FILTER_VALIDATE_BOOLEAN),
    // Local tests only, never on live: connections without TLS (the password would cross the network in clear)
    'allow_plain' => filter_var($env('MAIL_CLIENT_ALLOW_PLAIN', 'false'), FILTER_VALIDATE_BOOLEAN),

    // Seconds to wait for the mail server to answer
    'timeout' => (int) $env('MAIL_CLIENT_TIMEOUT', 20),

    // Check the server's certificate (only ever off for a local test server)
    'verify_tls' => filter_var($env('MAIL_CLIENT_VERIFY_TLS', 'true'), FILTER_VALIDATE_BOOLEAN),

    'sync' => [
        // Mail newer than this is copied into bureau (headers and text); older mail is read from the server
        'months' => 12,
        // Seconds one run of scripts/cron/mail_sync.php may spend in total (it runs every minute)
        'budget' => 50,
        // Messages fetched per IMAP command
        'batch' => 40,
        // Folders other than the inbox are checked at most this often (minutes)
        'other_folders_every' => 5,
        // The folder list is read again this often (minutes)
        'folders_every' => 30,
        // Bodies larger than this (bytes, each of text and HTML) are read from the server when opened
        'max_body_bytes' => 1000000,
        // New mail older than this (hours) never raises mail.received (catching up after a pause is not "new")
        'notify_within_hours' => 48,
    ],

    // A reply whose subject matches and shares a person joins an earlier conversation up to this many days later
    'thread_subject_days' => 30,

    // Largest attachment handed out in one piece (bytes)
    'max_attachment_bytes' => 30 * 1024 * 1024,

    // EHLO name and the domain of new Message-IDs when an address has none
    'local_domain' => 'bureau.lpc.cm',
];
