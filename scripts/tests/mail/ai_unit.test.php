<?php
/**
 * scripts/tests/mail/ai_unit.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 5 (Praxis AI), without a database: quoted text cut from French
 * and English mail, the search's words, the Ask answer escaped with [n] links to
 * the given messages only, drafts turned into escaped HTML, Gemini's JSON
 * answers, the model names allowed (Gemini only), French dates without intl.
 *
 * Run:  php scripts/tests/mail/ai_unit.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Ai\Gemini;
use LPC\Mail\Ai\MailAsk;
use LPC\Mail\Ai\MailSearch;
use LPC\Mail\Ai\MailText;
use LPC\Mail\Ai\Settings;
use LPC\Mail\Ai\WriteWithAi;

section('Quoted text is cut (what Praxis AI reads)');
$fr = "Bonjour,\nLa livraison de jeudi nous convient.\n\nLe lun. 2 nov. 2026 à 10:04, LPC Service client <contact@lpc.cm> a écrit :\n> Pouvons-nous livrer jeudi ?\n> Cordialement";
check('French Gmail "Le … a écrit :" and what follows', MailText::withoutQuotes($fr) === "Bonjour,\nLa livraison de jeudi nous convient.", MailText::withoutQuotes($fr));
$outlook = "Merci, c'est noté.\n\nDe : Marc Etoundi <marc@epicerie.test>\nEnvoyé : lundi 2 novembre 2026 09:12\nÀ : contact@lpc.cm\nObjet : Facture\n\nAncienne demande";
check('French Outlook "De : … Envoyé :" block', MailText::withoutQuotes($outlook) === "Merci, c'est noté.", MailText::withoutQuotes($outlook));
$en = "Thanks, see you Thursday.\n\nOn Mon, 2 Nov 2026 at 10:04, Awa <awa@client.test> wrote:\n> Old text";
check('English "On … wrote:"', MailText::withoutQuotes($en) === 'Thanks, see you Thursday.');
check('"-----Message d\'origine-----"', MailText::withoutQuotes("Voir plus bas.\n-----Message d'origine-----\nDe : x") === 'Voir plus bas.');
check('"> " lines anywhere', MailText::withoutQuotes("Oui.\n> non\nD'accord.") === "Oui.\nD'accord.");
check('a message that starts with a quote header keeps its text', MailText::withoutQuotes('Le prix est bon.') === 'Le prix est bon.');
$html = '<p>Parfait pour jeudi.</p><div class="gmail_quote"><div>Le lun. 2 nov., X a écrit :</div><blockquote>Ancien texte</blockquote></div>';
check('HTML: Gmail quote block removed', MailText::fromHtml($html) === 'Parfait pour jeudi.', MailText::fromHtml($html));
check('HTML: blockquote removed', MailText::fromHtml('<p>Oui</p><blockquote>non</blockquote><p>Merci</p>') === "Oui\n\nMerci" || MailText::fromHtml('<p>Oui</p><blockquote>non</blockquote><p>Merci</p>') === "Oui\nMerci", MailText::fromHtml('<p>Oui</p><blockquote>non</blockquote><p>Merci</p>'));
check('clip adds an ellipsis', MailText::clip(str_repeat('a', 20), 10) === str_repeat('a', 10) . ' …');

section('The search\'s words');
check('French filler words dropped', MailSearch::terms('Qu\'est-ce que Marc a dit sur la facture de septembre ?') === ['marc', 'facture', 'septembre'], json_encode(MailSearch::terms('Qu\'est-ce que Marc a dit sur la facture de septembre ?')));
check('short codes kept ("T4", "n°")', in_array('t4', MailSearch::terms('Le devis T4 ?'), true));
check('elision split ("d\'Awa" → awa)', in_array('awa', MailSearch::terms('Le message d\'Awa'), true) && in_array('awa', MailSearch::terms('Le message d’Awa'), true));
check('addresses kept whole', in_array('marc@epicerie.test', MailSearch::terms('Mails de marc@epicerie.test'), true));
check('at most 8 words', count(MailSearch::terms('un deux trois quatre cinq six sept huit neuf dix onze douze treize')) === 8);
check('a question of filler gives no words', MailSearch::terms('Qu\'est-ce que tu as reçu ?') === []);

section('The Ask answer is escaped; [n] links only to the messages given');
$sources = [['n' => 1, 'url' => '/modules/mail/index.php?box=3&thread=7'], ['n' => 2, 'url' => '/modules/mail/index.php?box=3&thread=9']];
$out = MailAsk::answerHtml("Marc a renvoyé la facture [1].\n- Montant : 120 000 FCFA [2]\n- <img src=x onerror=alert(1)> [9]\n**Gras** <script>alert(1)</script>", $sources);
check('no tag from the model survives', !str_contains($out, '<img') && !str_contains($out, '<script') && str_contains($out, '&lt;script&gt;'), $out);
check('[1] is a link to message 1', str_contains($out, '<a class="mai-cite" href="/modules/mail/index.php?box=3&amp;thread=7"'), $out);
check('[9] (not given) is dropped', !str_contains($out, '[9]') && substr_count($out, 'mai-cite') === 2, $out);
check('"- " lines become a list', str_contains($out, '<ul><li>Montant : 120 000 FCFA'), $out);
check('Markdown bold markers removed', !str_contains($out, '**'));
check('a link the model writes stays text', !str_contains(MailAsk::answerHtml('Voir <a href="https://evil.test">ici</a>', $sources), '<a href="https://evil.test"'));

section('Drafts become escaped HTML for the composer');
$h = WriteWithAi::html("Bonjour Awa,\n\nMerci <b>pour</b> la commande.\nÀ jeudi.\n\nCordialement,\nBruno");
check('paragraphs and line breaks', $h === '<p>Bonjour Awa,</p><p>Merci &lt;b&gt;pour&lt;/b&gt; la commande.<br>À jeudi.</p><p>Cordialement,<br>Bruno</p>', $h);

section('Gemini: JSON answers, Gemini models only');
check('JSON in a ```json fence', Gemini::json("```json\n{\"gist\": \"ok\"}\n```") === ['gist' => 'ok']);
check('plain JSON', Gemini::json('{"gist": "ok"}') === ['gist' => 'ok']);
check('not JSON → null', Gemini::json('Voici le résumé') === null && Gemini::json(null) === null);
check('gemini-2.5-flash allowed', Settings::validModel('gemini-2.5-flash') && Settings::validModel('gemini-2.0-flash-lite'));
check('other providers refused', !Settings::validModel('deepseek-chat') && !Settings::validModel('gpt-4o'));
check('nothing that could change the URL', !Settings::validModel('gemini-2.5-flash:generateContent?key=x') && !Settings::validModel('gemini-../../x') && !Settings::validModel('gemini-2.5/../flash') && !Settings::validModel("gemini-2.5-flash\n"));
check('default model is a Gemini flash model', preg_match('/^gemini-[\d.]+-flash$/', (string) Settings::config('default_model')) === 1);
check('limits per person per hour: 30 drafts, 30 summaries, 20 questions', Settings::config('limits.write') === 30 && Settings::config('limits.summary') === 30 && Settings::config('limits.ask') === 20);
check('at most the last 30 messages', Settings::config('context_messages') === 30);

section('French dates without intl');
check('dateLabel', Settings::dateLabel('2026-11-02 14:05:00') === '2 nov. 2026 à 14:05' && Settings::dateLabel('2026-08-01 09:00:00', false) === '1 août 2026');
check('longDate', Settings::longDate(new DateTimeImmutable('2026-11-02 10:00:00')) === 'lundi 2 novembre 2026');
check('no date → empty', Settings::dateLabel(null) === '');

mail_test_done();
