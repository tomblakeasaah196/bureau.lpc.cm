<?php
/**
 * scripts/tests/mail/mail_wire.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 1: the hand-written IMAP and SMTP clients over a real socket,
 * against scripted servers (support/scripted_imap_server.php and
 * support/scripted_smtp_server.php) that answer the way cPanel's Dovecot and
 * Exim do: literals, LITERAL+, SASL-IR, CONDSTORE, APPENDUID, COPYUID, NO
 * answers, multi-line EHLO, AUTH PLAIN, dot-stuffing. Ported from JBS HQ's
 * ImapClientWireTest, plus SMTP.
 *
 * Run:  php scripts/tests/mail/mail_wire.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Imap\ImapClient;
use LPC\Mail\Imap\ImapException;
use LPC\Mail\Imap\SocketImapSession;
use LPC\Mail\MailSendException;
use LPC\Mail\Mime\Message;
use LPC\Mail\Smtp\SmtpClient;

/** Start a scripted server; @return array{0: int port, 1: resource process, 2: array pipes, 3: string script file} */
function serve(string $server, array $script): array
{
    $file = tempnam(sys_get_temp_dir(), 'mail');
    file_put_contents($file, json_encode($script));
    $proc = proc_open([PHP_BINARY, __DIR__ . '/support/' . $server, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    if ($port <= 0) {
        fwrite(STDERR, "The scripted server did not start\n");
        exit(2);
    }

    return [$port, $proc, $pipes, $file];
}

/** Wait for the server; FAIL if it saw an unexpected command */
function finish(array $srv, string $label): void
{
    [, $proc, $pipes, $file] = $srv;
    $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $p) {
        @fclose($p);
    }
    proc_close($proc);
    @unlink($file);
    check($label . ': the server got exactly the expected commands', !str_contains((string) $err, 'MISMATCH'), trim((string) $err));
}

section('IMAP session against a Dovecot-like server');
$header = "From: =?UTF-8?Q?Ren=C3=A9e?= <renee@x.fr>\r\nSubject: Hello\r\n\r\n";
$srv = serve('scripted_imap_server.php', [
    'greeting' => '* OK [CAPABILITY IMAP4rev1 LITERAL+ SASL-IR AUTH=PLAIN] Dovecot ready.',
    'steps' => [
        ['expect' => '^AUTHENTICATE PLAIN ' . preg_quote(base64_encode("\0moi@lpc.cm\0s3cret"), '#') . '$',
            'reply' => ['TAG OK [CAPABILITY IMAP4rev1 LITERAL+ SASL-IR ENABLE CONDSTORE MOVE UIDPLUS SPECIAL-USE] Logged in']],
        ['expect' => '^ENABLE CONDSTORE$', 'reply' => ['* ENABLED CONDSTORE', 'TAG OK Enabled.']],
        ['expect' => '^LIST "" "\*" RETURN \(SPECIAL-USE\)$', 'reply' => [
            '* LIST (\HasChildren) "." INBOX',
            '* LIST (\HasNoChildren \Sent) "." INBOX.Sent',
            '* LIST (\HasNoChildren) "." "INBOX.&AMk-l&AOk-ments"',
            "* LIST (\\HasNoChildren \\Trash) \".\" {11}\r\nINBOX.Trash\r\n",
            'TAG OK List completed.',
        ]],
        ['expect' => '^EXAMINE "INBOX" \(CONDSTORE\)$', 'reply' => [
            '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft)',
            '* OK [PERMANENTFLAGS ()] Read-only mailbox.',
            '* 3 EXISTS', '* 0 RECENT',
            '* OK [UIDVALIDITY 1700000000] UIDs valid',
            '* OK [UIDNEXT 9] Predicted next UID',
            '* OK [HIGHESTMODSEQ 55] Highest',
            'TAG OK [READ-ONLY] Examine completed.',
        ]],
        ['expect' => '^UID SEARCH SINCE 1-Nov-2025$', 'reply' => ['* SEARCH 5 7 8', 'TAG OK Search completed.']],
        ['expect' => '^UID FETCH 7 \(UID FLAGS INTERNALDATE RFC822\.SIZE BODYSTRUCTURE BODY\.PEEK\[HEADER\.FIELDS \(FROM .*\)\] MODSEQ\)$', 'reply' => [
            '* 2 FETCH (UID 7 MODSEQ (54) FLAGS (\Seen) INTERNALDATE "30-Oct-2026 09:51:00 +0100" RFC822.SIZE 1234 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 5 1 NIL NIL NIL NIL) BODY[HEADER.FIELDS (FROM SUBJECT)] {' . strlen($header) . "}\r\n" . $header . ")\r\n",
            'TAG OK Fetch completed.',
        ]],
        ['expect' => '^UID FETCH 7:8 \(UID BODY\.PEEK\[1\]\)$', 'reply' => [
            "* 2 FETCH (UID 7 BODY[1] {5}\r\nHello)\r\n",
            "* 3 FETCH (UID 8 BODY[1] \"Bye\")\r\n",
            'TAG OK Fetch completed.',
        ]],
        ['expect' => '^UID FETCH 1:\* \(UID FLAGS MODSEQ\) \(CHANGEDSINCE 50\)$', 'reply' => [
            '* 3 FETCH (UID 8 FLAGS (\Seen \Flagged) MODSEQ (55))', 'TAG OK Fetch completed.',
        ]],
        ['expect' => '^APPEND "INBOX\.Sent" \(\\\\Seen\) \{20\+\}\r\nFrom: me\r\n\r\nHi there$', 'reply' => ['TAG OK [APPENDUID 1700000001 12] Append completed.']],
        ['expect' => '^SELECT "INBOX" \(CONDSTORE\)$', 'reply' => ['* 3 EXISTS', '* OK [UIDVALIDITY 1700000000] ok', 'TAG OK [READ-WRITE] Select completed.']],
        ['expect' => '^UID MOVE 7 "INBOX\.Trash"$', 'reply' => ['* OK [COPYUID 1700000002 7 31] Moved UIDs.', '* 2 EXPUNGE', 'TAG OK Move completed.']],
        ['expect' => '^UID STORE 5 \+FLAGS\.SILENT \(\\\\Bogus\)$', 'reply' => ['TAG NO Flag not allowed']],
        ['expect' => '^LOGOUT$', 'reply' => ['* BYE Logging out', 'TAG OK Logout completed.']],
    ],
]);
$client = new ImapClient('127.0.0.1', $srv[0], 'none', 5, false);
$client->connect();
$client->login('moi@lpc.cm', 's3cret');
$session = new SocketImapSession($client);
check('CONDSTORE enabled, MOVE known', $session->hasCapability('CONDSTORE') && $session->hasCapability('MOVE'));
$folders = $session->folders();
check('folders listed, a literal path read', array_column($folders, 'path') === ['INBOX', 'INBOX.Sent', 'INBOX.&AMk-l&AOk-ments', 'INBOX.Trash']);
check('French folder name decoded, attributes kept', $folders[2]['name'] === 'Éléments' && $folders[1]['attributes'] === ['\\HasNoChildren', '\\Sent'] && $folders[3]['name'] === 'Trash');
check('EXAMINE answers', $session->examine('INBOX') === ['uidvalidity' => 1700000000, 'uidnext' => 9, 'exists' => 3, 'highestmodseq' => 55]);
check('UID SEARCH', $session->search('SINCE 1-Nov-2025') === [5, 7, 8]);
$summary = $session->fetchSummaries([7])[0];
check('a summary: uid, flags, size, modseq, headers, structure', $summary['uid'] === 7 && $summary['flags'] === ['\\Seen'] && $summary['size'] === 1234
    && $summary['modseq'] === 54 && $summary['headers'] === $header && $summary['structure'][0] === 'TEXT');
check('bodies of two messages in one command', $session->fetchSectionsBatch([7, 8], ['1']) === [7 => ['1' => 'Hello'], 8 => ['1' => 'Bye']]);
check('flags changed since a modseq', $session->fetchFlags([], 50) === [8 => ['flags' => ['\\Seen', '\\Flagged'], 'modseq' => 55]]);
check('APPEND with LITERAL+ gives the new UID', $session->append('INBOX.Sent', "From: me\n\nHi there", ['\\Seen']) === 12);
$session->select('INBOX');
check('MOVE gives old UID => new UID', $session->move([7], 'INBOX.Trash') === [7 => 31]);
$e = throws(fn () => $session->addFlags([5], ['\\Bogus']));
check('a NO answer throws, with the server\'s words', $e instanceof ImapException && $e->reason === 'command' && str_contains($e->getMessage(), 'Flag not allowed'));
$session->logout();
finish($srv, 'IMAP');

section('IMAP login with a synchronising literal, refused');
$srv = serve('scripted_imap_server.php', [
    'greeting' => '* OK [CAPABILITY IMAP4rev1 AUTH=LOGIN] ready',
    'steps' => [['expect' => '^LOGIN "moi@lpc\.cm" \{10\}\r\npässwörd$', 'reply' => ['TAG NO [AUTHENTICATIONFAILED] Authentication failed.']]],
]);
$client = new ImapClient('127.0.0.1', $srv[0], 'none', 5, false);
$client->connect();
$e = throws(fn () => $client->login('moi@lpc.cm', 'pässwörd'));
check('a refused password is reason auth', $e instanceof ImapException && $e->reason === 'auth');
check('and the message never holds the password', $e && !str_contains($e->getMessage(), 'pässwörd'));
$client->close();
finish($srv, 'IMAP login');

check('an unreachable server throws', throws(function () {
    (new ImapClient('127.0.0.1', 1, 'none', 2, false))->connect();
}) instanceof ImapException);
$e = throws(fn () => (new ImapClient('127.0.0.1', 1, 'ssl', 2, false))->connect());
check('a refused SSL connection is "unreachable", not a certificate problem', $e instanceof ImapException && $e->reason === 'unreachable', $e ? $e->reason . ': ' . $e->getMessage() : '');

section('SMTP: EHLO, AUTH PLAIN, envelope with Bcc, dot-stuffing');
$srv = serve('scripted_smtp_server.php', [
    'greeting' => '220 mail.lpc.cm ESMTP Exim 4.98',
    'steps' => [
        ['expect' => '^EHLO bureau\.lpc\.cm$', 'reply' => ['250-mail.lpc.cm Hello', '250-SIZE 52428800', '250-8BITMIME', '250-AUTH PLAIN LOGIN', '250 HELP']],
        ['expect' => '^AUTH PLAIN ' . preg_quote(base64_encode("\0contact@lpc.cm\0s3cret"), '#') . '$', 'reply' => ['235 Authentication succeeded']],
        ['expect' => '^MAIL FROM:<contact@lpc\.cm>$', 'reply' => ['250 OK']],
        ['expect' => '^RCPT TO:<awa@client\.cm>$', 'reply' => ['250 Accepted']],
        ['expect' => '^RCPT TO:<audit@lpc\.cm>$', 'reply' => ['250 Accepted']],
        ['expect' => '^DATA$', 'reply' => ['354 Enter message, ending with "." on a line by itself']],
        ['data' => true, 'expect' => '^<DATA>$', 'reply' => ['250 OK id=1vXyZ-000123-AB']],
        ['expect' => '^QUIT$', 'reply' => ['221 mail.lpc.cm closing connection']],
    ],
]);
$m = (new Message())->from('contact@lpc.cm', 'LPC')->to('awa@client.cm')->bcc('audit@lpc.cm')->subject('Test')->text("Ligne 1\n.\n..deux points");
$smtp = new SmtpClient('127.0.0.1', $srv[0], 'none', 5, false, 'bureau.lpc.cm');
$smtp->connect();
check('EHLO extensions read', isset($smtp->extensions()['AUTH']) && $smtp->extensions()['AUTH'] === 'PLAIN LOGIN');
$smtp->login('contact@lpc.cm', 's3cret');
$raw = $m->toString();
$smtp->send('contact@lpc.cm', $m->recipients(), $raw);
$smtp->quit();
$data = (string) @file_get_contents($srv[3] . '.data');
@unlink($srv[3] . '.data');
finish($srv, 'SMTP');
check('lines starting with a dot were doubled on the wire', str_contains($data, "\r\n..\r\n") && str_contains($data, "\r\n...deux points"));
check('un-stuffed, the server got the message exactly', preg_replace('/^\.\./m', '.', $data) === $raw . (str_ends_with($raw, "\r\n") ? '' : "\r\n"));
check('no Bcc header on the wire', !preg_match('/^Bcc:/mi', $data));

section('SMTP: a refused password and a refused recipient');
$srv = serve('scripted_smtp_server.php', [
    'greeting' => '220 mail.lpc.cm ESMTP',
    'steps' => [
        ['expect' => '^EHLO ', 'reply' => ['250-mail.lpc.cm', '250 AUTH PLAIN']],
        ['expect' => '^AUTH PLAIN ', 'reply' => ['535 Incorrect authentication data']],
        ['expect' => '^QUIT$', 'reply' => ['221 bye']],
    ],
]);
$smtp = new SmtpClient('127.0.0.1', $srv[0], 'none', 5, false);
$smtp->connect();
$e = throws(fn () => $smtp->login('contact@lpc.cm', 'mauvais'));
$smtp->quit();
finish($srv, 'SMTP auth');
check('535 is reason auth, permanent', $e instanceof MailSendException && $e->reason === 'auth' && $e->permanent && $e->smtpCode === 535);
check('the message never holds the password', $e && !str_contains($e->getMessage(), 'mauvais'));

$srv = serve('scripted_smtp_server.php', [
    'greeting' => '220 mail.lpc.cm ESMTP',
    'steps' => [
        ['expect' => '^EHLO ', 'reply' => ['250-mail.lpc.cm', '250 AUTH PLAIN']],
        ['expect' => '^AUTH PLAIN ', 'reply' => ['235 ok']],
        ['expect' => '^MAIL FROM:', 'reply' => ['250 OK']],
        ['expect' => '^RCPT TO:<nobody@lpc\.cm>$', 'reply' => ['550 Unrouteable address']],
        ['expect' => '^QUIT$', 'reply' => ['221 bye']],
    ],
]);
$smtp = new SmtpClient('127.0.0.1', $srv[0], 'none', 5, false);
$smtp->connect();
$smtp->login('contact@lpc.cm', 'ok');
$e = throws(fn () => $smtp->send('contact@lpc.cm', ['nobody@lpc.cm'], "Subject: x\r\n\r\nx"));
$smtp->quit();
finish($srv, 'SMTP recipient');
check('550 on a recipient is rejected, permanent', $e instanceof MailSendException && $e->reason === 'rejected' && $e->permanent && str_contains($e->getMessage(), 'Unrouteable'));
check('an unreachable SMTP server throws unreachable', ($u = throws(fn () => (new SmtpClient('127.0.0.1', 1, 'none', 2, false))->connect())) instanceof MailSendException && $u->reason === 'unreachable');

mail_test_done();
