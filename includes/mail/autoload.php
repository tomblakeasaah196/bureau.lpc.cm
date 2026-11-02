<?php
/**
 * includes/mail/autoload.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier (Mail): loads the LPC\Mail engine.
 *
 * Every Mail entry point (pages, api/v1/mail/*, scripts/cron/mail_*.php, the
 * tests) requires this one file. It maps LPC\Mail\Foo\Bar to
 * includes/mail/Foo/Bar.php, so the engine works with or without Composer's
 * vendor/ folder. includes/ is refused over HTTP by the root .htaccess.
 *
 * The engine needs a PDO connection: LPC\Mail\Db uses the app's Database
 * singleton unless a test hands it another one (Db::use()). Passwords are
 * encrypted with the app's Crypto class (APP_ENCRYPTION_KEY), loaded here.
 *
 * Runbook: docs/MAIL.md.
 * -----------------------------------------------------------------------------
 */

if (defined('LPC_MAIL_AUTOLOAD')) {
    return;
}
define('LPC_MAIL_AUTOLOAD', true);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'LPC\\Mail\\', 9) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!class_exists('Crypto', false)) {
    require_once __DIR__ . '/../classes/Crypto.php';
}
