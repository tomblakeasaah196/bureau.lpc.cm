<?php
/**
 * scripts/tests/mail/mail_unit.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 1: the parts that need no server and no database. IMAP responses
 * as cPanel's Dovecot sends them, BODYSTRUCTURE, folder names, headers and
 * charsets as real mail sends them, thread subject keys, folder roles, and the
 * outgoing message builder (read back with the same parser sync uses).
 * Ported from JBS HQ's ImapParsingTest and MimeTest, plus the builder.
 *
 * Run:  php scripts/tests/mail/mail_unit.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Config;
use LPC\Mail\HostPolicy;
use LPC\Mail\Imap\BodyStructure;
use LPC\Mail\Imap\ImapClient;
use LPC\Mail\Imap\ResponseParser;
use LPC\Mail\Imap\Utf7;
use LPC\Mail\MailError;
use LPC\Mail\MailServer;
use LPC\Mail\Mime\Decoder;
use LPC\Mail\Mime\Headers;
use LPC\Mail\Mime\Message;
use LPC\Mail\Mime\Text;
use LPC\Mail\Sync\FolderRoles;
use LPC\Mail\Sync\Threader;

section('IMAP responses');
$tokens = ResponseParser::tokens('12 FETCH (UID 345 FLAGS (\\Seen \\Answered) INTERNALDATE "30-Oct-2026 09:51:00 +0100" BODY[HEADER.FIELDS (FROM SUBJECT)] ' . "\0L0\0" . ' X NIL)', ["From: a@b.c\r\n\r\n"]);
$items = ResponseParser::fetchItems($tokens[2]);
check('a FETCH reads into items', $tokens[1] === 'FETCH' && $items['UID'] === '345' && $items['FLAGS'] === ['\\Seen', '\\Answered']);
check('the header literal is cut out whole', $items['HEADER'] === "From: a@b.c\r\n\r\n" && $items['X'] === null);
$items = ResponseParser::fetchItems(ResponseParser::tokens('(BODY[1]<0> "say \\"hi\\" \\\\ ok")')[0]);
check('quoted strings keep escapes; partial bodies lose their origin', $items['BODY[1]'] === 'say "hi" \\ ok');
check('untagged OK with a code', ResponseParser::untagged('OK [UIDVALIDITY 3857529045] UIDs valid') === ['type' => 'OK', 'code' => 'UIDVALIDITY', 'args' => '3857529045', 'text' => 'UIDs valid']);
check('SEARCH with CONDSTORE keeps the numbers only', ResponseParser::untagged('SEARCH 2 84 882 (MODSEQ 917162500)') === ['type' => 'SEARCH', 'data' => [2, 84, 882]]);
check('empty SEARCH', ResponseParser::untagged('SEARCH') === ['type' => 'SEARCH', 'data' => []]);
check('CAPABILITY is upper-cased', in_array('CONDSTORE', ResponseParser::untagged('CAPABILITY IMAP4rev1 condstore MOVE')['data'], true));
check('numbered EXISTS', ResponseParser::untagged('23 EXISTS') === ['type' => 'EXISTS', 'num' => 23, 'data' => []]);
check('LIST with attributes', ResponseParser::untagged('LIST (\\HasNoChildren \\Sent) "." INBOX.Sent')['data'] === [['\\HasNoChildren', '\\Sent'], '.', 'INBOX.Sent']);
check('UID sets are compact', ImapClient::uidSet([10, 3, 1, 2, 9, 7, 7]) === '1:3,7,9:10' && ImapClient::uidSet([5]) === '5' && ImapClient::uidSet([]) === '');
check('astring quotes simple values', ImapClient::astring('INBOX.Sent') === '"INBOX.Sent"' && ImapClient::astring('pa"ss') === '"pa\\"ss"');
check('astring sends 8-bit values as literals', ImapClient::astring('pässwörd') === ['literal' => 'pässwörd']);

section('Folder names (modified UTF-7)');
check('French folder names decode', Utf7::decode('&AMk-l&AOk-ments envoy&AOk-s') === 'Éléments envoyés');
check('and encode back', Utf7::encode('Éléments envoyés') === '&AMk-l&AOk-ments envoy&AOk-s');
check('& is escaped', Utf7::decode('R&-D') === 'R&D' && Utf7::encode('R&D') === 'R&-D');
check('non-Latin round trip', Utf7::decode(Utf7::encode('台北')) === '台北');

section('BODYSTRUCTURE');
$raw = '((("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "QUOTED-PRINTABLE" 1520 40 NIL NIL NIL NIL)("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "QUOTED-PRINTABLE" 9800 120 NIL NIL NIL NIL) "ALTERNATIVE" ("BOUNDARY" "b1") NIL NIL NIL)("APPLICATION" "PDF" ("NAME" "Devis T4.pdf") NIL NIL "BASE64" 400000 NIL ("ATTACHMENT" ("FILENAME" "Devis T4.pdf" "SIZE" "292000")) NIL NIL) "MIXED" ("BOUNDARY" "b0") NIL NIL NIL)';
$s = BodyStructure::summarise(ResponseParser::tokens($raw)[0]);
check('Outlook: text and HTML found', $s['text'] === ['section' => '1.1', 'encoding' => 'quoted-printable', 'charset' => 'utf-8', 'size' => 1520] && $s['html']['section'] === '1.2');
check('the PDF is one attachment, decoded size estimated', count($s['attachments']) === 1 && $s['attachments'][0]['section'] === '2'
    && $s['attachments'][0]['filename'] === 'Devis T4.pdf' && $s['attachments'][0]['mime'] === 'application/pdf'
    && $s['attachments'][0]['size'] === 300000 && !$s['attachments'][0]['is_inline']);
$s = BodyStructure::summarise(ResponseParser::tokens('("TEXT" "PLAIN" ("CHARSET" "iso-8859-1") NIL NIL "7BIT" 120 4 NIL NIL NIL NIL)')[0]);
check('a single part is section 1', $s['text']['section'] === '1' && $s['text']['charset'] === 'iso-8859-1' && $s['html'] === null);
$s = BodyStructure::summarise(ResponseParser::tokens('(("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "BASE64" 2000 30 NIL NIL NIL NIL)("IMAGE" "PNG" ("NAME" "logo.png") "<logo@sig>" NIL "BASE64" 4000 NIL ("INLINE" ("FILENAME" "logo.png")) NIL NIL) "RELATED" ("BOUNDARY" "r") NIL NIL NIL)')[0]);
check('an inline logo is inline, with its Content-ID', $s['html']['section'] === '1' && $s['attachments'][0]['content_id'] === 'logo@sig' && $s['attachments'][0]['is_inline']);
$s = BodyStructure::summarise(ResponseParser::tokens('(("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 10 1 NIL NIL NIL NIL)("APPLICATION" "OCTET-STREAM" NIL NIL NIL "BASE64" 100 NIL ("ATTACHMENT" ("FILENAME*" "utf-8\'\'Re%CC%81sume%CC%81%20%E2%82%AC.pdf")) NIL NIL)("APPLICATION" "PDF" ("NAME" "=?UTF-8?B?RmFjdHVyZSDDqXTDqS5wZGY=?=") NIL NIL "BASE64" 100 NIL NIL NIL NIL) "MIXED" NIL NIL NIL NIL)')[0]);
check('RFC 2231 file names', $s['attachments'][0]['filename'] === "Re\u{0301}sume\u{0301} €.pdf");
check('encoded-word file names', $s['attachments'][1]['filename'] === 'Facture été.pdf');
$s = BodyStructure::summarise(ResponseParser::tokens('(("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 10 1 NIL NIL NIL NIL)("MESSAGE" "RFC822" NIL NIL NIL "7BIT" 500 ("date" "subj" NIL NIL NIL NIL NIL NIL NIL NIL) ("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 20 1 NIL NIL NIL NIL) 12 NIL NIL NIL NIL) "MIXED" NIL NIL NIL NIL)')[0]);
check('an attached message is one attachment', $s['text']['section'] === '1' && count($s['attachments']) === 1 && $s['attachments'][0]['filename'] === 'message-2.eml');

section('Headers and charsets');
check('encoded words (Q and B)', Headers::decode('=?UTF-8?Q?R=C3=A9union_du_conseil?=') === 'Réunion du conseil' && Headers::decode('=?UTF-8?B?RmFjdHVyZSDDqXTDqQ==?=') === 'Facture été');
check('a character split across two words', Headers::decode('=?UTF-8?B?Q2Fm?= =?UTF-8?B?w6k=?=') === 'Café');
check('Latin-1 words', Headers::decode('=?ISO-8859-1?Q?Gr=FC=DFe?=') === 'Grüße');
$list = Headers::addresses('"Mbarga, Awa" <awa@client.cm>, prenom.nom@lpc.cm, =?UTF-8?Q?Ren=C3=A9e?= <renee@x.fr>, team: a@t.co, b@t.co;, (Ops) ops@y.cm');
check('address lists with quotes, groups and comments', $list === [
    ['name' => 'Mbarga, Awa', 'email' => 'awa@client.cm'],
    ['name' => null, 'email' => 'prenom.nom@lpc.cm'],
    ['name' => 'Renée', 'email' => 'renee@x.fr'],
    ['name' => null, 'email' => 'a@t.co'],
    ['name' => null, 'email' => 'b@t.co'],
    ['name' => 'Ops', 'email' => 'ops@y.cm'],
], json_encode($list));
check('undisclosed recipients', Headers::addresses('undisclosed-recipients:;') === [] && Headers::addresses(null) === []);
$h = Headers::parse("Subject: A long\r\n subject line\r\nReferences: <a@x>\r\n\t<b@y>\r\nMessage-ID: <c@z>\r\n");
check('headers unfold', Headers::first($h, 'subject') === 'A long subject line');
check('Message-IDs without brackets', Headers::messageIds($h['references'][0]) === ['a@x', 'b@y'] && Headers::messageIds($h['message-id'][0]) === ['c@z']);
check('dates in UTC (Date header)', Headers::date('Fri, 30 Oct 2026 09:51:00 +0100 (WAT)')?->format('Y-m-d H:i:s') === '2026-10-30 08:51:00');
check('dates in UTC (INTERNALDATE)', Headers::date('30-Oct-2026 09:51:00 +0100')?->format('Y-m-d H:i:s') === '2026-10-30 08:51:00'
    && Headers::date(' 1-Jul-2026 02:44:25 +0000')?->format('Y-m-d H:i:s') === '2026-07-01 02:44:25');
check('a date without seconds gets :00, not the current second', Headers::date('Fri, 30 Oct 2026 09:51 +0100')?->format('Y-m-d H:i:s') === '2026-10-30 08:51:00');
check('an unreadable date is null', Headers::date('pas une date') === null);
check('ISO-8859-1 quoted-printable', Decoder::decode(quoted_printable_encode(mb_convert_encoding('déjà vu', 'ISO-8859-1', 'UTF-8')), 'quoted-printable', 'iso-8859-1') === 'déjà vu');
check('base64 UTF-8', Decoder::decode(base64_encode('naïve'), 'base64', 'utf-8') === 'naïve');
check('labelled UTF-8 but really Windows-1252', Decoder::toUtf8("\x805 caf\xe9", 'utf-8') === '€5 café');
check('an unknown charset still gives valid UTF-8', mb_check_encoding(Decoder::toUtf8("abc\xff", 'x-unknown-charset'), 'UTF-8'));

section('Text, snippets, threads, folder roles');
check('plain text from HTML', Text::fromHtml('<html><head><style>p{}</style></head><body><p>Bonjour&nbsp;Awa,</p><p>Voir <b>ci-dessous</b>.</p><ul><li>Un</li></ul></body></html>') === "Bonjour Awa,\nVoir ci-dessous.\n\n• Un");
check('snippets stop at the quoted history (English)', Text::snippet("Thanks, all good.\n\nOn Fri, 30 Oct 2026 at 09:51, Ada <ada@x.com> wrote:\n> earlier") === 'Thanks, all good.');
check('snippets stop at the quoted history (French)', Text::snippet("Merci.\nLe ven. 30 oct. 2026 à 09:51, Awa <awa@x.cm> a écrit :\n> avant") === 'Merci.');
check('subject keys drop Re/TR/Fwd', Threader::subjectKey('RE: Fwd: TR: Devis pour   T4') === 'devis pour t4' && Threader::subjectKey('Re[2]: AW: Budget') === 'budget');
check('reply subjects', Threader::isReplySubject('TR: Budget') && !Threader::isReplySubject('Regarding budget'));
$f = fn ($path, $attrs = []) => ['path' => $path, 'name' => substr($path, (int) strrpos($path, '.') + (str_contains($path, '.') ? 1 : 0)), 'delimiter' => '.', 'attributes' => $attrs];
$roles = FolderRoles::assign([$f('INBOX'), $f('INBOX.Sent'), $f('INBOX.Drafts'), $f('INBOX.spam'), $f('INBOX.Trash'), $f('INBOX.Archive'), $f('INBOX.Clients')]);
check('cPanel Dovecot folders get their roles', $roles === ['INBOX' => 'inbox', 'INBOX.Sent' => 'sent', 'INBOX.Drafts' => 'drafts', 'INBOX.spam' => 'spam', 'INBOX.Trash' => 'trash', 'INBOX.Archive' => 'archive', 'INBOX.Clients' => 'other']);
$roles = FolderRoles::assign([$f('INBOX'), $f('INBOX.Sent'), $f('INBOX.Sent Items', ['\\Sent'])]);
check('special-use flags win over names', $roles['INBOX.Sent Items'] === 'sent' && $roles['INBOX.Sent'] === 'other');
$roles = FolderRoles::assign([$f('INBOX'), $f('INBOX.Envoyés'), $f('INBOX.Corbeille'), $f('INBOX.Brouillons')]);
check('French folder names', $roles['INBOX.Envoyés'] === 'sent' && $roles['INBOX.Corbeille'] === 'trash' && $roles['INBOX.Brouillons'] === 'drafts');

section('Outgoing messages (Mime\\Message)');
$m = (new Message())
    ->from('contact@lpc.cm', 'LPC Service client')
    ->to('Awa Mbarga <awa@client.cm>')->cc([['name' => 'Équipe ventes', 'email' => 'ventes@lpc.cm']])->bcc('audit@lpc.cm')
    ->subject('Devis n° 12 — été 2026')
    ->text("Bonjour Awa,\n.\nVoici le devis.")->html('<p>Bonjour Awa,</p><p>Voici le <b>devis</b>.</p><img src="cid:logo@lpc">')
    ->attach('%PDF-1.4 fake', 'Devis été.pdf', 'application/pdf')
    ->embed("\x89PNG fake", 'logo.png', 'image/png', 'logo@lpc')
    ->header('In-Reply-To', '<abc@client.cm>')->header('References', '<abc@client.cm>')
    ->messageId('x1.20261102@lpc.cm')->date(new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('Africa/Douala')));
$raw = $m->toString();
[$head, $body] = explode("\r\n\r\n", $raw, 2);
$h = Headers::parse($head);
check('CRLF line ends only', !preg_match("/(?<!\r)\n/", $raw));
check('From is the mailbox, with its name', Headers::addresses($h['from'][0] ?? '')[0] === ['name' => 'LPC Service client', 'email' => 'contact@lpc.cm']);
check('To and Cc read back (accented name encoded)', Headers::addresses($h['to'][0])[0]['email'] === 'awa@client.cm' && Headers::addresses($h['cc'][0])[0] === ['name' => 'Équipe ventes', 'email' => 'ventes@lpc.cm']);
check('no Bcc header, but Bcc is in the envelope', !isset($h['bcc']) && $m->recipients() === ['awa@client.cm', 'ventes@lpc.cm', 'audit@lpc.cm']);
check('the subject reads back', Headers::first($h, 'subject') === 'Devis n° 12 — été 2026');
check('Message-ID, In-Reply-To and Date', Headers::messageIds($h['message-id'][0]) === ['x1.20261102@lpc.cm'] && Headers::messageIds($h['in-reply-to'][0]) === ['abc@client.cm']
    && Headers::date($h['date'][0])?->format('Y-m-d H:i') === '2026-11-02 09:00');
check('mixed > related > alternative', str_contains($h['content-type'][0], 'multipart/mixed') && str_contains($body, 'multipart/related') && str_contains($body, 'multipart/alternative'));
check('the inline picture has its Content-ID', str_contains($body, 'Content-ID: <logo@lpc>') && str_contains($body, 'Content-Disposition: inline'));
check('the accented file name uses RFC 2231', str_contains($body, "filename*=utf-8''Devis%20%C3%A9t%C3%A9.pdf"));
check('the PDF is base64 and decodes', str_contains($body, base64_encode('%PDF-1.4 fake')));
check('the text part is quoted-printable UTF-8', str_contains($body, 'Content-Type: text/plain; charset=utf-8') && str_contains($body, quoted_printable_encode("Bonjour Awa,\r\n.\r\nVoici le devis.")));
$plain = (new Message())->from('a@lpc.cm')->to('b@client.cm')->subject('Simple')->text('Salut')->toString();
check('a plain message is one text part', str_contains($plain, "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nSalut") && !str_contains($plain, 'multipart'));
check('no recipient: refused', throws(fn () => (new Message())->from('a@lpc.cm')->text('x')->toString()) instanceof InvalidArgumentException);
check('a bad address: refused', throws(fn () => (new Message())->to('not an address')) instanceof InvalidArgumentException);
check('header injection is impossible', throws(fn () => (new Message())->header("X-Test\r\nBcc", 'x')) instanceof InvalidArgumentException
    && !str_contains((new Message())->from('a@lpc.cm')->to('b@c.cm')->subject("Hi\r\nBcc: evil@x.cm")->toString(), "\r\nBcc:"));
$long = (new Message())->from('a@lpc.cm')->to(array_map(fn ($i) => "person{$i}@client.cm", range(1, 12)))->text('x')->toString();
$longest = max(array_map('strlen', explode("\r\n", explode("\r\n\r\n", $long, 2)[0])));
check('long header lines are folded', $longest <= 78, "longest header line {$longest}");

section('Which servers bureau connects to (HostPolicy)');
$ok = ['imap_host' => 'mail.lpc.cm', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'mail.lpc.cm', 'smtp_port' => 465, 'smtp_encryption' => 'ssl'];
$refused = function (array $settings): ?MailError {
    $e = throws(fn () => HostPolicy::check($settings));

    return $e instanceof MailError ? $e : null;
};
check('mail.lpc.cm with SSL/TLS is allowed', throws(fn () => HostPolicy::check($ok)) === null);
check('STARTTLS is allowed too', throws(fn () => HostPolicy::check(['imap_encryption' => 'starttls', 'smtp_encryption' => 'starttls'] + $ok)) === null);
$e = $refused(['imap_host' => 'imap.gmail.com'] + $ok);
check('a host outside the allow-list is refused, on its field, in French', $e && $e->field === 'imap_host' && $e->status === 422 && str_contains($e->getMessage(), 'serveurs de messagerie autorisés'));
$e = $refused(['smtp_host' => 'evil.example'] + $ok);
check('the outgoing server too', $e && $e->field === 'smtp_host');
$e = $refused(['imap_host' => '127.0.0.1'] + $ok);
check('an IP literal is refused (loopback)', $e && $e->field === 'imap_host' && str_contains($e->getMessage(), 'pas une adresse IP'));
check('an IP literal is refused (the box\'s own public IP)', $refused(['smtp_host' => '37.59.83.88'] + $ok)?->field === 'smtp_host');
Config::set('allowed_hosts', ['mail.partner.cm', 'internal.lpc.cm', 'gone.lpc.cm', 'v6.lpc.cm', '127.0.0.1']);
$GLOBALS['__mail_dns'] += ['mail.partner.cm' => ['41.202.219.70'], 'internal.lpc.cm' => ['192.168.1.20'], 'v6.lpc.cm' => ['2001:41d0::1', '::1']];
check('a host the owner adds (MAIL_CLIENT_ALLOWED_HOSTS) is allowed', throws(fn () => HostPolicy::check(['imap_host' => 'mail.partner.cm', 'smtp_host' => 'mail.partner.cm'] + $ok)) === null);
$e = $refused(['imap_host' => 'internal.lpc.cm'] + $ok);
check('an allowed name resolving to a private address is refused', $e && $e->field === 'imap_host' && str_contains($e->getMessage(), 'adresse interne'));
check('one internal address among several is enough to refuse (IPv6 loopback)', $refused(['smtp_host' => 'v6.lpc.cm'] + $ok)?->field === 'smtp_host');
check('an allowed name that does not resolve is refused', str_contains((string) $refused(['imap_host' => 'gone.lpc.cm'] + $ok)?->getMessage(), 'introuvable'));
check('an IP literal stays refused even when listed', $refused(['imap_host' => '127.0.0.1'] + $ok)?->field === 'imap_host');
foreach (['127.0.0.1', '10.1.2.3', '172.16.0.9', '192.168.0.1', '169.254.1.1', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1'] as $ip) {
    if (HostPolicy::isPublic($ip)) {
        check("{$ip} is not public", false);
    }
}
check('loopback, private, link-local, shared and reserved ranges are never public', true);
check('public addresses are public', HostPolicy::isPublic('37.59.83.88') && HostPolicy::isPublic('2001:41d0::1'));
$e = $refused(['imap_encryption' => 'none'] + $ok);
check('a connection without TLS is refused', $e && $e->field === 'imap_host' && str_contains($e->getMessage(), 'en clair'));
check('for SMTP too', $refused(['smtp_encryption' => 'none'] + $ok)?->field === 'smtp_host');
Config::set('allow_plain', true);
check('plain is allowed only behind the test switch (MAIL_CLIENT_ALLOW_PLAIN)', throws(fn () => HostPolicy::check(['imap_encryption' => 'none', 'smtp_encryption' => 'none'] + $ok)) === null);
Config::set('allow_private_hosts', true);
check('internal hosts only behind the test switch (MAIL_CLIENT_ALLOW_PRIVATE_HOSTS)', throws(fn () => HostPolicy::check(['imap_host' => '127.0.0.1', 'smtp_host' => 'internal.lpc.cm'] + $ok)) === null);
check('the allow-list still applies with the test switches on', $refused(['imap_host' => '10.0.0.1'] + $ok)?->field === 'imap_host');
Config::reset();
check('the switches are off by default (live)', Config::get('allow_plain') === false && Config::get('allow_private_hosts') === false && HostPolicy::allowedHosts() === ['mail.lpc.cm']);
$e = throws(fn () => (new MailServer())->connect(['imap_host' => '127.0.0.1'] + $ok, 'a@lpc.cm', 'x'));
check('the real IMAP door refuses before opening any socket', $e instanceof MailError && $e->field === 'imap_host');
$e = throws(fn () => (new MailServer())->checkSmtp(['smtp_host' => 'smtp.example.org'] + $ok, 'a@lpc.cm', 'x'));
check('the real SMTP door too', $e instanceof MailError && $e->field === 'smtp_host');

mail_test_done();
