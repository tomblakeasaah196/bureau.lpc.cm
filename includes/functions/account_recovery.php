<?php
/**
 * includes/functions/account_recovery.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — self-service account recovery (2 Nov 2026).
 *
 * A forgotten password is reset through a one-hour, single-use link emailed to
 * the account's address. A forgotten address is recovered from the matricule as
 * a masked hint, and the link can be sent to it without showing it in full.
 * The actions live in api/v1/password_controller.php, the page in
 * public/auth/password_forgot.php.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../classes/Mail.php';

/**
 * Whether the email reset flow is on. Only when mail really leaves the host:
 * Mail::send() logs and returns true without MAIL_FROM, and a "we sent you a
 * link" that sent nothing is the dead end Sprint 14 removed.
 */
function lpc_reset_by_email_enabled(): bool
{
    return Mail::configured();
}

/**
 * Enough of an address to recognise it, too little to copy it:
 * "jean-baptiste.assah@lpc.cm" -> "j***-b***.a***@lpc.cm". The domain stays
 * whole: it is the company's, and it tells the user which mailbox to open.
 */
function lpc_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '***';
    }
    $masked = '';
    foreach (preg_split('/([._+-])/', substr($email, 0, $at), -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        if ($part === '') continue;
        $masked .= preg_match('/^[._+-]$/', $part) ? $part : mb_substr($part, 0, 1) . '***';
    }
    return $masked . substr($email, $at);
}
