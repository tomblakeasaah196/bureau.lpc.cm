<?php
/**
 * scripts/tests/mail/read_unit.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 2 (reading), without a database: the HTML sanitiser (scripts,
 * handlers, forms, frames, javascript: links and CSS tricks removed; remote
 * pictures held back and counted; cid: pictures through bureau; quotes folded;
 * a forwarded message never folded), the frame's own policy, plain text made
 * safe with links and folded quotes, French dates, and search words.
 * Ported from JBS HQ's ReadSanitizerTest.
 *
 * Run:  php scripts/tests/mail/read_unit.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Clock;
use LPC\Mail\Read\Format;
use LPC\Mail\Read\HtmlSanitizer;
use LPC\Mail\Read\Reader;
use LPC\Mail\Read\TextFormatter;
use LPC\Mail\Read\ThreadList;

$clean = fn (string $html, bool $remote = false, ?callable $cid = null) => HtmlSanitizer::clean($html, $remote, $cid);

section('Sanitiser: what never reaches the reader');
$out = $clean('<p>Bonjour</p><script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)">lien</a>'
    . '<form action="https://evil.example"><input name="pw"><button>Envoyer</button></form><iframe src="https://evil.example"></iframe>'
    . '<svg><script>alert(4)</script></svg><object data="x.swf"></object><meta http-equiv="refresh" content="0;url=https://evil.example">'
    . '<base href="https://evil.example/"><link rel="stylesheet" href="https://evil.example/a.css"><div onclick="alert(5)" onmouseover="x()">texte</div>');
$h = strtolower($out['html']);
check('keeps the words', str_contains($out['html'], 'Bonjour') && str_contains($out['html'], 'texte') && str_contains($out['html'], 'lien'));
check('no <script>', !str_contains($h, '<script'));
check('no event handler attribute', !preg_match('/\son[a-z]+\s*=/', $h), $out['html']);
check('no javascript: link', !str_contains($h, 'javascript:'));
check('no form, input, button', !str_contains($h, '<form') && !str_contains($h, '<input') && !str_contains($h, '<button'));
check('no iframe, svg, object, meta, base, link', !preg_match('/<(iframe|svg|object|meta|base|link)\b/', $h), $out['html']);
check('a picture without a safe source leaves nothing', !str_contains($h, '<img'));

section('Sanitiser: links');
$out = $clean('<a href="https://lpc.cm/x" target="_top">site</a> <a href="mailto:awa@client.cm">écrire</a> <a href="tel:+237600">appeler</a> <a href="#haut">haut</a> <a href="data:text/html,x">data</a> <a href="  JaVaScRiPt:alert(1)">x</a> <a href="vbscript:x">v</a>');
check('web links open in a new tab without referrer', str_contains($out['html'], 'href="https://lpc.cm/x" target="_blank" rel="noopener noreferrer nofollow"'), $out['html']);
check('mailto and tel kept', str_contains($out['html'], 'href="mailto:awa@client.cm"') && str_contains($out['html'], 'href="tel:+237600"'));
check('in-page anchor stays in the frame', str_contains($out['html'], 'href="#haut" target="_self"'));
check('data:, javascript: (any case, spaces), vbscript: dropped', !preg_match('/href="(data|javascript|vbscript)/i', $out['html']) && substr_count($out['html'], 'href=') === 4, $out['html']);

section('Sanitiser: remote pictures held back, cid: and data: pictures');
$html = '<p>Promo</p><img src="https://tracker.example/open.gif" alt="Logo"><img src="//cdn.example/a.png">'
    . '<table background="https://x.example/bg.png"><tr><td style="background:url(\'https://x.example/td.png\')">x</td></tr></table>'
    . '<img src="cid:logo@lpc"><img src="data:image/png;base64,iVBORw0KGgo="><img src="data:image/svg+xml;base64,PHN2Zz4=">';
$cid = fn (string $id) => $id === 'logo@lpc' ? '/api/v1/mail/read_file.php?m=1&cid=logo%40lpc' : null;
$held = $clean($html, false, $cid);
check('4 remote pictures counted as held back', $held['blocked'] === 4, (string) $held['blocked']);
check('no remote address left', !str_contains($held['html'], 'tracker.example') && !str_contains($held['html'], 'x.example') && !str_contains($held['html'], 'cdn.example'), $held['html']);
check('a held-back picture keeps its alt text', str_contains($held['html'], '[Logo]'));
check('cid: picture goes through bureau', str_contains($held['html'], 'src="/api/v1/mail/read_file.php?m=1&amp;cid=logo%40lpc"'), $held['html']);
check('small data: PNG kept, data: SVG dropped', str_contains($held['html'], 'data:image/png;base64') && !str_contains($held['html'], 'svg+xml'));
$shown = $clean($html, true, $cid);
check('allowed: remote pictures load (protocol-relative made https)', $shown['blocked'] === 0 && str_contains($shown['html'], 'https://tracker.example/open.gif') && str_contains($shown['html'], 'https://cdn.example/a.png'));

section('Sanitiser: CSS');
$out = $clean('<style>@import url(https://evil.example/x.css); .a{color:red} .b{width:expression(alert(1))} .c{background:url(https://t.example/p.gif)} .d{background-image:image-set("https://t.example/q.png" 1x)} </style>'
    . '<p style="color:blue;background:url(javascript:alert(1))">a</p><p style="behavior:url(x.htc)">b</p><p style="\\65 xpression(alert(1))">c</p><p style="font-size:14px">d</p>');
check('<style> moved out of the body, cleaned', !str_contains($out['html'], '<style') && str_contains($out['styles'], '.a{color:red}'));
check('@import, expression() and remote url() gone from <style>', !preg_match('/@import|expression|t\.example/i', $out['styles']), $out['styles']);
check('image-set() made void', !str_contains($out['styles'], 'image-set('));
check('a style attribute with a script URL or behaviour is dropped whole', !str_contains($out['html'], 'javascript') && !str_contains($out['html'], 'behavior') && !str_contains($out['html'], 'color:blue'), $out['html']);
check('CSS escapes cannot hide expression()', !preg_match('/xpression/i', $out['html']));
check('plain styles kept', str_contains($out['html'], 'style="font-size:14px"'));
check('<style> text can never close its tag', !str_contains($clean('<style>.a{}</style><p>x</p>')['styles'], '<'));

section('Sanitiser: comments, unknown tags, encoding, empty');
$out = $clean('<!--[if mso]><b>Outlook</b><![endif]--><o:p>mot</o:p><custom-tag>autre</custom-tag><p>Déjà vu – « ok »</p>');
check('Outlook comments removed', !str_contains($out['html'], '<!--'));
check('unknown tags unwrapped, words kept', str_contains($out['html'], 'mot') && str_contains($out['html'], 'autre') && !str_contains($out['html'], '<custom-tag'));
check('accents and French quotes intact (UTF-8)', str_contains($out['html'], 'Déjà vu – « ok »'), $out['html']);
$out = $clean('<html><head><meta charset="windows-1252"></head><body><p>Don’t</p></body></html>');
check('a sender\'s <meta charset> cannot garble the text', str_contains($out['html'], 'Don’t'), $out['html']);
check('empty HTML gives nothing', $clean('   ')['html'] === '');
$out = $clean('<body bgcolor="#f0f0f0" style="padding:8px"><p>x</p></body>');
check('body colours move to the wrapper', str_contains($out['html'], 'class="mr-mail-body"') && str_contains($out['html'], 'padding:8px'), $out['html']);

section('Sanitiser: quoted text folded');
$gmail = $clean('<div dir="ltr">Merci, c’est noté.</div><div class="gmail_quote"><div>Le lun. 3 nov. 2026, Awa a écrit :</div><blockquote>Ancien</blockquote></div>');
check('Gmail quote folded in <details>', str_contains($gmail['html'], '<details class="mr-quote"><summary>Afficher le texte cité</summary><div class="gmail_quote">'), $gmail['html']);
$outlook = $clean('<p>Bien reçu.</p><div id="divRplyFwdMsg"><b>De :</b> Awa</div><div>Ancien message</div>');
check('Outlook reply header and what follows folded', (bool) preg_match('#<details class="mr-quote">.*divRplyFwdMsg.*Ancien message.*</details>#s', $outlook['html']), $outlook['html']);
$apple = $clean('<p>Oui.</p><blockquote type="cite">Avant</blockquote>');
check('Apple cite folded', str_contains($apple['html'], '<details class="mr-quote">'));
$only = $clean('<div class="gmail_quote">Tout est cité</div>');
check('never folds the whole message', !str_contains($only['html'], '<details'));
$fwd = $clean('<p>Pour info</p><div class="gmail_quote">---------- Message transféré ---------<br>De : Awa</div>');
check('a forwarded message is not folded', !str_contains($fwd['html'], '<details'), $fwd['html']);

section('The frame: its own policy');
$doc = Reader::document('<p>x</p>', '.a{color:red}', false);
check('policy: no scripts, pictures from bureau and data: only', str_contains($doc, "default-src &#039;none&#039;; img-src &#039;self&#039; data:;") && !str_contains($doc, 'https:'), $doc);
check('policy: no forms, no base', str_contains($doc, "form-action &#039;none&#039;") && str_contains($doc, "base-uri &#039;none&#039;"));
check('links open outside the frame', str_contains($doc, '<base target="_blank">'));
check('the sender\'s cleaned CSS follows the frame\'s own', strpos($doc, '.a{color:red}') > strpos($doc, '.mr-mail-body{'));
$doc = Reader::document('<p>x</p>', '', true);
check('allowed: remote pictures and fonts', str_contains($doc, "img-src &#039;self&#039; data: https: http:") && str_contains($doc, 'font-src data: https:'));
$p = Reader::present('texte', '<p>Bonjour</p><img src="https://t.example/x.gif">', false, fn () => null);
check('present(): HTML in the frame, pictures counted', $p['kind'] === 'html' && $p['blocked'] === 1 && str_contains($p['doc'], 'Bonjour'));
$p = Reader::present("Bonjour\nà vous", '<p>   </p>', false, fn () => null);
check('present(): HTML without words falls back to the text', $p['kind'] === 'text' && str_contains($p['html'], 'Bonjour'));
check('present(): nothing at all', Reader::present('  ', null, false, fn () => null)['kind'] === 'empty');

section('Plain text');
$t = TextFormatter::toHtml("Bonjour <b>Awa</b> & co,\nvoir https://lpc.cm/devis?id=4&x=1. ou écrire à ventes@lpc.cm\n\nLe lun. 3 nov. 2026 à 10:00, Awa <awa@client.cm> a écrit :\n> Ancien message\n> encore");
check('escaped', str_contains($t, 'Bonjour &lt;b&gt;Awa&lt;/b&gt; &amp; co'));
check('web link (no trailing dot), with &amp;', str_contains($t, 'href="https://lpc.cm/devis?id=4&amp;x=1" target="_blank" rel="noopener noreferrer nofollow">https://lpc.cm/devis?id=4&amp;x=1</a>.'), $t);
check('mail address linked', str_contains($t, 'href="mailto:ventes@lpc.cm"'));
check('French "a écrit :" quote folded', str_contains($t, '<details class="mr-quote"><summary>Afficher le texte cité</summary>') && strpos($t, 'Le lun. 3 nov.') > strpos($t, '<details'));
$t = TextFormatter::toHtml("D'accord.\n\nOn Mon, 3 Nov 2026 at 10:00, Ada <ada@x.com>\nwrote:\n> old");
check('"On … wrote:" wrapped on two lines folded', str_contains($t, '<details') && strpos($t, 'On Mon') > strpos($t, '<details'), $t);
$t = TextFormatter::toHtml("Merci.\n\n-----Message d'origine-----\nDe : Awa\nObjet : Devis");
check('Outlook "Message d\'origine" folded', str_contains($t, '<details') && strpos($t, 'Message d') > strpos($t, '<details'));
$t = TextFormatter::toHtml("Vu.\n\nDe : Awa Mbarga\nEnvoyé : lundi 3 novembre 2026 10:00\nÀ : contact@lpc.cm");
check('Outlook "De : / Envoyé :" block folded', str_contains($t, '<details') && strpos($t, 'De : Awa') > strpos($t, '<details'), $t);
$t = TextFormatter::toHtml("> tout\n> est cité");
check('a message that is all quote is not folded', !str_contains($t, '<details'));
check('a javascript: "link" in text stays text', !str_contains(TextFormatter::toHtml('javascript:alert(1) "><script>x</script>'), '<script') && !str_contains(TextFormatter::toHtml('javascript:alert(1)'), 'href'));

section('French dates and names');
Clock::freeze(new DateTimeImmutable('2026-11-03 15:00:00', new DateTimeZone('Africa/Douala')));
check('today: the time', Format::when('2026-11-03 10:42:00') === '10:42');
check('this year: day and month', Format::when('2026-08-14 10:42:00') === '14 août');
check('this year with time', Format::when('2026-02-01 08:05:00', true) === '1 févr., 08:05');
check('before: with the year', Format::when('2025-12-24 10:00:00') === '24 déc. 2025');
check('full date', Format::full('2026-11-03 10:42:00') === '3 nov. 2026, 10:42');
check('no date: empty', Format::when(null) === '' && Format::full('') === '');
Clock::freeze(null);
check('people: the mailbox itself left out', Format::people([['name' => 'LPC', 'email' => 'contact@lpc.cm'], ['name' => '', 'email' => 'awa@client.cm'], ['name' => 'Marc', 'email' => 'm@x.cm']], 'contact@lpc.cm') === 'awa@client.cm, Marc');
check('addresses', Format::addresses([['name' => 'Awa', 'email' => 'awa@client.cm'], ['name' => null, 'email' => 'b@x.cm']]) === 'Awa <awa@client.cm>, b@x.cm');
check('initials', Format::initials('Awa Mbarga') === 'AM' && Format::initials('ventes@lpc.cm') === 'V' && Format::initials('') === '@');
check('icon name cannot inject', Format::icon('flag" onclick="x') === '<i class="fa-solid fa-flagonclickx" aria-hidden="true"></i>');

section('Search words');
[$w, $f] = ThreadList::parse('devis "bon de livraison" de:awa from:marc T4');
check('words and quoted phrases', $w === ['devis', 'bon de livraison', 'T4'], json_encode($w));
check('de: and from: look at the sender', $f === ['awa', 'marc'], json_encode($f));
check('FULLTEXT terms: required prefixes, short words left to LIKE', ThreadList::fullText(['awa@client.cm', 'T4', 'devis']) === '+awa* +client* +devis*', ThreadList::fullText(['awa@client.cm', 'T4', 'devis']));
check('at most 8 words, 200 characters', count(ThreadList::parse(str_repeat('mot ', 30))[0]) === 8);

mail_test_done();
