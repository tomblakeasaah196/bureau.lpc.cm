<?php

namespace LPC\Mail\Read;

/**
 * The Mail page's HTML pieces (includes/mail/Read/partials/*.php): the same PHP draws the first screen
 * (modules/mail/index.php) and the pieces the page script swaps in later (api/v1/mail/read.php), so a list row or
 * a message looks the same either way. Each partial escapes what it prints (View::e) and answers 404 when reached
 * by URL (includes/ is also refused by the root .htaccess).
 */
final class Partial
{
    public static function render(string $name, array $vars = []): string
    {
        if (!preg_match('/^[a-z_]+$/', $name)) {
            return '';
        }
        $file = __DIR__ . '/partials/' . $name . '.php';
        if (!is_file($file)) {
            return '';
        }
        if (!defined('LPC_MAIL_READ_PARTIAL')) {
            define('LPC_MAIL_READ_PARTIAL', true);
        }
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                include $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
