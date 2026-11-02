<?php
/**
 * scripts/tests/mail/compose_unit.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 3 (writing and signatures), without a database: the composer's
 * HTML cleaner (what the editor sends is never trusted), addresses as typed or
 * pasted, the signature design (tables and inline styles only, escaped, short
 * and none, logo, legal line, plain-text twin), the quoted original in French,
 * file types without fileinfo, and the composer's settings.
 *
 * Run:  php scripts/tests/mail/compose_unit.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Compose\ComposeConfig;
use LPC\Mail\Compose\HtmlCleaner;
use LPC\Mail\Compose\MessageBuilder;
use LPC\Mail\Compose\Outbox;
use LPC\Mail\Compose\Recipients;
use LPC\Mail\Compose\Signatures;

section('HTML cleaner: what the editor sends');
$dirty = '<p style="color:red" onclick="steal()">Bonjour <b>Marc</b></p><script>alert(1)</script><style>p{}</style>'
    . '<img src="https://evil.test/x.png" onerror="x()"><iframe src="https://evil.test"></iframe>'
    . '<a href="javascript:alert(1)">piège</a> <a href="https://lpc.cm" target="_blank" onmouseover="x()">site</a>'
    . ' <a href="mailto:a@b.cm">écrire</a> <a href="tel:+237696291800">appeler</a> <a href="data:text/html,x">data</a>'
    . '<table><tr><td>cellule</td></tr></table><span class="x">texte</span><!-- commentaire -->';
$clean = HtmlCleaner::clean($dirty);
check('no script, style, image, frame or comment', !preg_match('/<(script|style|img|iframe)|<!--|alert\(1\)/i', $clean), $clean);
check('no attributes but a link’s address', !preg_match('/\s(style|onclick|onerror|onmouseover|target|class)=/i', $clean), $clean);
check('formatting kept', str_contains($clean, '<p>Bonjour <b>Marc</b></p>'));
check('http(s), mailto and tel links kept; javascript: and data: unwrapped to their text', str_contains($clean, '<a href="https://lpc.cm">site</a>') && str_contains($clean, '<a href="mailto:a@b.cm">')
    && str_contains($clean, '<a href="tel:+237696291800">') && !str_contains($clean, 'javascript:') && !str_contains($clean, 'data:') && str_contains($clean, 'piège') && str_contains($clean, 'data'));
check('tables and spans unwrapped, their words kept', str_contains($clean, 'cellule') && str_contains($clean, 'texte') && !str_contains($clean, '<table') && !str_contains($clean, '<span'));
check('an editor with only empty lines is empty', HtmlCleaner::clean('<p><br></p><div>&nbsp;</div>') === '' && HtmlCleaner::isBlank('<p> </p>') && !HtmlCleaner::isBlank('<p>a</p>'));
check('accents survive (UTF-8)', HtmlCleaner::clean('<p>Évêque à Douala, ça marche</p>') === '<p>Évêque à Douala, ça marche</p>');
$outlook = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1252"></head><body><p>Don’t worry, c’est réglé</p></body></html>';
check('an original’s own charset meta does not garble the text (JBS Fix 2)', str_contains(HtmlCleaner::clean($outlook), 'Don’t worry, c’est réglé'));

$text = HtmlCleaner::toText('<p>Bonjour,</p><ul><li>un</li><li>deux</li></ul><p>Voir <a href="https://lpc.cm/tarifs">nos tarifs</a> ou <a href="mailto:info@lpc.cm">info@lpc.cm</a>.</p><blockquote>cité</blockquote>');
check('plain text: paragraphs, list dashes, links as “text (address)”, quotes with >', str_contains($text, "Bonjour,\n") && str_contains($text, '- un') && str_contains($text, 'nos tarifs (https://lpc.cm/tarifs)')
    && str_contains($text, 'info@lpc.cm.') && !str_contains($text, '(info@lpc.cm)') && str_contains($text, '> cité'), $text);
check('plain text as safe paragraphs', HtmlCleaner::fromText("Ligne 1\nLigne <2>\n\nParagraphe") === "<p>Ligne 1<br>\nLigne &lt;2&gt;</p><p>Paragraphe</p>");

section('Addresses');
$r = Recipients::parse('Awa Mbarga <AWA@client.cm>, "Ndi, Paul" <paul@x.cm>; bob@x.cm, pas-une-adresse, awa@client.cm');
check('names, quoted commas, ; and , separators, lower case, once each', $r['valid'] === [
    ['name' => 'Awa Mbarga', 'email' => 'awa@client.cm'], ['name' => 'Ndi, Paul', 'email' => 'paul@x.cm'], ['name' => null, 'email' => 'bob@x.cm'],
], json_encode($r['valid']));
check('what is not an address is reported', $r['invalid'] === ['pas-une-adresse']);
check('lists from the browser', Recipients::parse([['name' => 'A <b>', 'email' => ' A@B.CM '], ['email' => 'x']])['valid'] === [['name' => 'A b', 'email' => 'a@b.cm']]);
check('format', Recipients::format(['name' => 'Awa', 'email' => 'a@b.cm']) === 'Awa <a@b.cm>' && Recipients::format(['name' => null, 'email' => 'a@b.cm']) === 'a@b.cm');
check('without', Recipients::without([['name' => null, 'email' => 'a@b.cm'], ['name' => null, 'email' => 'c@d.cm']], ['A@B.cm']) === [['name' => null, 'email' => 'c@d.cm']]);

section('The signature design');
$person = ['name' => 'Awa <Mbarga>', 'title' => 'Comptable', 'department' => 'Finance & co', 'phone' => '+237 233 42 02 81', 'mobile' => null];
$company = [
    'name' => 'La Petite Cour', 'tagline' => 'Distribution agroalimentaire', 'address' => 'B.P. 5120 · Douala, Cameroun', 'phone' => '+237 696 291 800',
    'website' => 'https://lpc.cm', 'logo_url' => 'https://bureau.lpc.cm/assets/img/mail/lpc-signature-logo.png', 'show_logo' => true,
    'show_legal' => false, 'legal' => 'RCCM RC/DLA/2019/A/A/687', 'brand' => '#005A2B', 'accent' => '#8CC63F',
];
$html = Signatures::render($person, $company, 'contact@lpc.cm', 'full');
check('escaped (name, service)', str_contains($html, 'Awa &lt;Mbarga&gt;') && str_contains($html, 'Finance &amp; co') && !str_contains($html, '<Mbarga>'));
check('email-safe: presentation tables, inline styles, no <style>, no class, no CSS layout', str_contains($html, 'role="presentation"') && !preg_match('/<style|class=|display:\s*(flex|grid)|position:/i', $html));
check('logo: PNG at 76 × 76 with the company as alt text, linked to the site', (bool) preg_match('#<a href="https://lpc.cm"[^>]*><img src="https://bureau\.lpc\.cm/[^"]+\.png" width="76" height="76" alt="La Petite Cour"#', $html));
check('LPC greens written as literal colours (CSS variables do not work in mail)', str_contains($html, '#005A2B') && str_contains($html, '#8CC63F') && !str_contains($html, 'var('));
check('the leaf rule: two cells, dark then leaf green', (bool) preg_match('#bgcolor="\#005A2B"[^>]*>&nbsp;</td><td width="44" height="3" bgcolor="\#8CC63F"#', $html));
check('phone as a tel: link; the office line labelled', str_contains($html, 'href="tel:+237233420281"') && str_contains($html, '>Tél.<'));
check('no mobile, no Mobile line; the company phone only when the person has none', !str_contains($html, '>Mobile<') && !str_contains($html, '696 291 800'));
check('site shown without https://', str_contains($html, '>lpc.cm</a>'));
check('company line: name, what it does, where', str_contains($html, '>La Petite Cour</span>') && str_contains($html, 'Distribution agroalimentaire') && str_contains($html, 'B.P. 5120 · Douala, Cameroun'));
check('legal line only when chosen', !str_contains($html, 'RCCM') && str_contains(Signatures::render($person, ['show_legal' => true] + $company, 'contact@lpc.cm'), 'RCCM RC/DLA/2019/A/A/687'));
$nologo = Signatures::render($person, ['show_logo' => false] + $company, 'contact@lpc.cm');
check('without the logo: no picture, no divider', !str_contains($nologo, '<img') && !str_contains($nologo, 'border-left:1px'));
$none = ['phone' => null, 'mobile' => null] + $person;
check('a person without numbers shows the company phone', str_contains(Signatures::render($none, $company, 'x@lpc.cm'), 'tel:+237696291800'));
$slim = Signatures::render($person, $company, 'contact@lpc.cm', 'slim');
check('short: one block with the leaf bar, name, role, company, numbers, address', str_contains($slim, 'border-left:3px solid #8CC63F') && str_contains($slim, 'Comptable · Finance &amp; co') && str_contains($slim, 'mailto:contact@lpc.cm') && !str_contains($slim, '<img'));
check('none and unknown styles are empty', Signatures::render($person, $company, 'x@lpc.cm', 'none') === '' && Signatures::render($person, $company, 'x@lpc.cm', 'fancy') === '');
$txt = Signatures::renderText($person, $company, 'contact@lpc.cm', 'full');
check('plain text: "-- " line, name, role, numbers, address, site', str_starts_with($txt, "-- \nAwa <Mbarga>\nComptable · Finance & co\n\nTél. +237 233 42 02 81\ncontact@lpc.cm · lpc.cm\n\nLa Petite Cour · Distribution agroalimentaire\nB.P. 5120"), $txt);
check('plain short', Signatures::renderText($person, $company, 'contact@lpc.cm', 'slim') === "-- \nAwa <Mbarga> · Comptable · Finance & co\nLa Petite Cour · +237 233 42 02 81 · contact@lpc.cm");

$e = throws(fn () => Signatures::checkCompany(['name' => 'LPC', 'website' => 'javascript:alert(1)']));
check('a website that is not one is refused', $e !== null && $e->field === 'website');
check('website without scheme gets https', Signatures::checkCompany(['name' => 'LPC', 'website' => 'www.lpc.cm/'])['website'] === 'https://www.lpc.cm');
check('numbers: digits, spaces, + and brackets', Signatures::checkNumbers(['phone' => ' +237  (0) 696-291-800 ', 'mobile' => ''])  === ['phone' => '+237 (0) 696-291-800', 'mobile' => null]);
$e = throws(fn () => Signatures::checkNumbers(['mobile' => '06 <script>']));
check('anything else is refused on its field', $e !== null && $e->field === 'mobile');

section('The quoted original');
$original = ['subject' => 'Facture', 'from_name' => 'Marc <E>', 'from_email' => 'marc@x.cm', 'sent_at' => '2026-11-01 10:05:00', 'received_at' => null,
    'to_list' => [['name' => 'LPC', 'email' => 'contact@lpc.cm']], 'cc_list' => []];
$q = MessageBuilder::quote('reply', $original, ['text' => "Bonjour <b>\nMerci", 'html' => null]);
check('reply: “Le 1er nov. 2026 à 10:05, … a écrit :” (no intl)', str_starts_with($q['text'], 'Le 1er nov. 2026 à 10:05, Marc E <marc@x.cm> a écrit :'), $q['text']);
check('quoted text escaped inside a blockquote, > in plain text', str_contains($q['html'], '<blockquote') && str_contains($q['html'], 'Bonjour &lt;b&gt;') && str_contains($q['text'], "> Bonjour <b>\n> Merci"));
$f = MessageBuilder::quote('forward', $original, ['text' => '', 'html' => '<p>Texte <i>HTML</i></p>']);
check('forward: French header, HTML original as text', str_contains($f['text'], "---------- Message transféré ----------\nDe : Marc E <marc@x.cm>\nDate : 1er nov. 2026 à 10:05\nObjet : Facture\nÀ : LPC <contact@lpc.cm>") && str_contains($f['text'], 'Texte HTML'), $f['text']);

section('Files and settings');
check('types from the first bytes (no fileinfo on the live server)', Outbox::mimeOf('x.bin', '%PDF-1.7') === 'application/pdf' && Outbox::mimeOf('a', "\x89PNG\r\n") === 'image/png'
    && Outbox::mimeOf('a', "\xFF\xD8\xFF\xE0") === 'image/jpeg' && Outbox::mimeOf('a', 'RIFF1234WEBPVP8 ') === 'image/webp');
check('then from the name', Outbox::mimeOf('Tarifs.XLSX', 'PK') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' && Outbox::mimeOf('notes', 'abc') === 'application/octet-stream');
check('replies start with the short signature, new mail with the full one', Outbox::emptyPayload('reply')['signature'] === 'slim' && Outbox::emptyPayload('new')['signature'] === 'full' && Outbox::emptyPayload('forward')['signature'] === 'full');
check('undo window 10 s, 100 recipients, 18 MB of files, retries 1–60 min', ComposeConfig::get('undo_seconds') === 10 && ComposeConfig::get('max_recipients') === 100
    && ComposeConfig::get('max_attachment_bytes') === 18 * 1024 * 1024 && ComposeConfig::get('retry_minutes') === [1, 2, 5, 15, 30, 60]);
check('the logo is a PNG on bureau’s own host', (bool) preg_match('#^https?://[^/]+/assets/img/mail/lpc-signature-logo\.png$#', (string) ComposeConfig::get('logo_url')));
$png = dirname(__DIR__, 3) . '/assets/img/mail/lpc-signature-logo.png';
$size = is_file($png) ? getimagesize($png) : false;
check('…shipped at 240 × 240 (three times 76-80 px), under 40 KB', $size && $size[0] === 240 && $size[1] === 240 && $size['mime'] === 'image/png' && filesize($png) < 40 * 1024);
ComposeConfig::set('undo_seconds', 3);
check('tests can change a setting', ComposeConfig::get('undo_seconds') === 3);
ComposeConfig::reset();
check('…and put it back', ComposeConfig::get('undo_seconds') === 10);

mail_test_done();
