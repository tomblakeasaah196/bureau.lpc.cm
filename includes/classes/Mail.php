<?php
/**
 * includes/classes/Mail.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — minimal mail dispatcher.
 *
 * v1 (this file): PHP mail() with proper headers. If MAIL_FROM is empty in .env
 * the message is written to the error_log with a WARNING prefix so an admin
 * can manually deliver / forward while SMTP is being set up.
 *
 * Sprint 3 will replace this with PHPMailer + SMTP config from .env.
 * Consumers should only ever call Mail::send() so the swap is transparent.
 * -----------------------------------------------------------------------------
 */

class Mail
{
    /**
     * Send a plain-text email.
     *
     * @return bool  true if handed off to MTA (or logged when unconfigured)
     */
    public static function send(string $to, string $subject, string $body, array $extraHeaders = []): bool
    {
        $from     = env('MAIL_FROM',      '');   // e.g. "no-reply@bureau.lpc.cm"
        $fromName = env('MAIL_FROM_NAME', 'Bureau LPC');
        $replyTo  = env('MAIL_REPLY_TO',  $from);

        if ($from === '') {
            // SMTP not configured yet — spill to the error log for manual triage.
            error_log("[Mail:UNCONFIGURED] to={$to} subject={$subject}\n" . $body);
            return true;
        }

        $headers = array_merge([
            'From'                      => sprintf('%s <%s>', self::encHeader($fromName), $from),
            'Reply-To'                  => $replyTo,
            'Return-Path'               => $from,
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer'                  => 'Bureau-LPC/1.0',
        ], $extraHeaders);

        $hdrString = '';
        foreach ($headers as $k => $v) $hdrString .= "$k: $v\r\n";
        $hdrString = rtrim($hdrString, "\r\n");

        $subjectEncoded = self::encHeader($subject);

        try {
            return @mail($to, $subjectEncoded, $body, $hdrString);
        } catch (Throwable $e) {
            error_log('Mail::send failed: ' . $e->getMessage());
            return false;
        }
    }

    /** RFC 2047 encoded-word for headers containing non-ASCII. */
    private static function encHeader(string $s): string
    {
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }
}
