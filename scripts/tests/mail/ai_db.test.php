<?php
/**
 * scripts/tests/mail/ai_db.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 5 (Praxis AI), against a real database (CI: MariaDB 10.11) and
 * the FakeMailServer, with Gemini faked (Gemini::fake): the key stored encrypted
 * and never shown, the request's shape (key in a header, never in the URL), the
 * mail search never wider than what the person can read and never in
 * Indésirables, Corbeille or Brouillons, Ask citing only the messages it used,
 * summaries from the last 30 messages without quotes or attachments and cleared
 * by new mail, drafts that send nothing, the limits per person per hour, the
 * mounts shown only with a key, and nothing from the mail in the log.
 *
 * Run:  MAIL_TEST_DB_PORT=3307 MAIL_TEST_DB_NAME=bureau_mail_test_pr5 php scripts/tests/mail/ai_db.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Ai\Gemini;
use LPC\Mail\Ai\Limits;
use LPC\Mail\Ai\MailAsk;
use LPC\Mail\Ai\MailSearch;
use LPC\Mail\Ai\Settings;
use LPC\Mail\Ai\ThreadSummariser;
use LPC\Mail\Ai\Ui;
use LPC\Mail\Ai\WriteWithAi;
use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\MailError;
use LPC\Mail\MailSettings;
use LPC\Mail\Mount;
use LPC\Mail\Store;
use LPC\Mail\Sync\MailSync;
use LPC\Mail\Tests\FakeMailServer;

$pdo = mail_test_db();

// Everything logged during the run goes here, to prove nothing from the mail is ever in it
$log = tempnam(sys_get_temp_dir(), 'mail-ai-log');
ini_set('error_log', $log);

$admin = mail_test_user('Bruno Essomba', 'admin');
$awa = mail_test_user('Awa Mbarga', 'accountant');
$paul = mail_test_user('Paul Ndi', 'accountant');
$driver = mail_test_user('Jean Chauffeur', 'driver');
$gone = mail_test_user('Ancien Salarié', 'accountant', false);

$server = FakeMailServer::install();
$server->addMailbox('contact@lpc.cm', 'secret');
$server->addMailbox('awa.mbarga@lpc.cm', 'secret');
$server->addFolder('contact@lpc.cm', 'INBOX.Archive', ['\\Archive']);
$contact = mail_test_account('contact@lpc.cm', 'shared', $admin, 'secret', 'LPC Service client');
mail_test_member($contact, $awa, 'send');
mail_test_member($contact, $paul, 'read');
$own = mail_test_account('awa.mbarga@lpc.cm', 'personal', $awa);

$ago = fn (string $when) => (new DateTimeImmutable($when))->format('D, d M Y H:i:s O');
$facture = FakeMailServer::message(
    from: 'Marc Etoundi <marc@epicerie.test>', subject: 'Facture de septembre', messageId: 'f1@epicerie.test', date: $ago('-2 days'),
    text: "Bonjour,\nPouvez-vous renvoyer la facture de septembre ? Le montant est de 120 000 FCFA. MARQUEUR-CONTACT",
    attachments: [['name' => 'releve.pdf', 'mime' => 'application/pdf', 'content' => 'PIECE-JOINTE-SECRETE']],
);
$server->deliver('contact@lpc.cm', 'INBOX', $facture);
$server->deliver('contact@lpc.cm', 'INBOX.Archive', $facture, ['\\Seen']);   // the same message filed twice
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'SABC <commandes@sabc.test>', subject: 'Devis T4', text: 'Le devis T4 est prêt pour validation.', messageId: 't4@sabc.test', date: $ago('-1 day')));
$server->deliver('contact@lpc.cm', 'INBOX.Junk', FakeMailServer::message(from: 'Promo <promo@spam.test>', subject: 'Votre facture gagnante', text: 'INDESIRABLE-MARQUEUR facture septembre', messageId: 'junk1@spam.test', date: $ago('-1 day')));
$server->deliver('contact@lpc.cm', 'INBOX.Trash', FakeMailServer::message(from: 'Marc Etoundi <marc@epicerie.test>', subject: 'Ancienne facture', text: 'CORBEILLE-MARQUEUR facture septembre', messageId: 'trash1@epicerie.test', date: $ago('-3 days')));
$server->deliver('contact@lpc.cm', 'INBOX.Drafts', FakeMailServer::message(from: 'LPC Service client <contact@lpc.cm>', subject: 'Brouillon facture', text: 'BROUILLON-MARQUEUR facture septembre', messageId: 'draft1@lpc.cm', date: $ago('-1 day')), ['\\Draft']);
$server->deliver('awa.mbarga@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Paul Ndi <paul.ndi@lpc.cm>', to: 'awa.mbarga@lpc.cm', subject: 'Réunion de lundi', text: 'PERSONNEL-MARQUEUR la facture privée de septembre', messageId: 'p1@lpc.cm', date: $ago('-5 hours')));

// A conversation of 32 messages, then a spam copy in it
$prev = null;
$refs = '';
for ($i = 1; $i <= 32; $i++) {
    $n = sprintf('%02d', $i);
    $text = "Message {$n} sur la livraison à Bonamoussadi. L{$n}-MARQUEUR";
    if ($i === 32) {
        $text = "Jeudi matin nous convient. L32-MARQUEUR\n\nLe lun. 2 nov. 2026 à 10:04, LPC Service client <contact@lpc.cm> a écrit :\n> TEXTE-CITE-MARQUEUR";
    }
    $server->deliver('contact@lpc.cm', $i % 2 ? 'INBOX' : 'INBOX.Sent', FakeMailServer::message(
        from: $i % 2 ? 'Awa Client <awa@client.test>' : 'LPC Service client <contact@lpc.cm>',
        to: $i % 2 ? 'contact@lpc.cm' : 'awa@client.test',
        subject: $i === 1 ? 'Livraison Bonamoussadi' : 'Re: Livraison Bonamoussadi', text: $text,
        messageId: "l{$i}@client.test", inReplyTo: $prev, references: $refs !== '' ? trim($refs) : null,
        date: $ago('-3 days +' . ($i * 10) . ' minutes'),
    ), ['\\Seen']);
    $prev = "l{$i}@client.test";
    $refs .= " <l{$i}@client.test>";
}
$server->deliver('contact@lpc.cm', 'INBOX.Junk', FakeMailServer::message(from: 'Inconnu <x@spam.test>', subject: 'Re: Livraison Bonamoussadi', text: 'SPAM-DANS-FIL', messageId: 'spam-in-thread@spam.test', inReplyTo: 'l32@client.test', date: $ago('-1 hour')));
$server->deliver('contact@lpc.cm', 'INBOX.Junk', FakeMailServer::message(from: 'Arnaque <y@spam.test>', subject: 'Gros lot', text: 'Seulement en indésirables', messageId: 'spam-only@spam.test', date: $ago('-2 hours')));

MailSync::make()->account($contact['id'], null, true);
MailSync::make()->account($own['id'], null, true);
$msg = fn (string $messageId, ?string $role = 'inbox') => Store::message((int) Db::value(
    'SELECT m.id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.message_id = ?' . ($role ? ' AND f.role = ?' : '') . ' ORDER BY m.id LIMIT 1',
    $role ? [$messageId, $role] : [$messageId],
));
$livraison = $msg('l1@client.test')['thread_id'];
check('the conversation of 32 messages is one thread', (int) Db::value('SELECT COUNT(*) FROM mail_messages WHERE thread_id = ?', [$livraison]) >= 32);

// Gemini, faked: never the real API
$calls = [];
$answer = fn (string $text) => fn (array $r) => ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']]])];
$next = $answer('OK');
Gemini::fake(function (array $request) use (&$calls, &$next) {
    $calls[] = $request;

    return $next($request);
});
$lastPrompt = function () use (&$calls): string {
    return $calls ? $calls[count($calls) - 1]['body']['contents'][0]['parts'][0]['text'] : '';
};
$lastSystem = function () use (&$calls): string {
    return $calls ? $calls[count($calls) - 1]['body']['system_instruction']['parts'][0]['text'] : '';
};
$fakeKey = 'AIzaTEST-not-a-real-key-0000000000001';

section('Without a key, Praxis AI is off');
check('not configured', !Settings::configured() && !Ui::enabled($awa));
$before = count($calls);
check('Gemini::generate sends nothing and says why', Gemini::generate('s', 'p') === null && Gemini::$lastFailure === 'off' && count($calls) === $before);
$e = throws(fn () => MailAsk::answer($awa, 'la facture de septembre'));
check('Ask says so (422)', $e instanceof MailError && $e->status === 422);
$e = throws(fn () => ThreadSummariser::summarise($awa, $livraison));
check('Résumer says so (422)', $e instanceof MailError && $e->status === 422);
$e = throws(fn () => WriteWithAi::draft($awa, ['accountId' => $contact['id'], 'mode' => 'new'], 'Remercier'));
check('Écrire avec l\'IA says so, on the instruction', $e instanceof MailError && $e->field === 'instruction');

section('The key: admins only, encrypted, never shown, blank keeps it');
$e = throws(fn () => Settings::save($awa, $fakeKey, ''));
check('a staff member cannot set it (403)', $e instanceof MailError && $e->status === 403 && !Settings::configured());
$e = throws(fn () => Settings::save($admin, 'not a key', ''));
check('a key with spaces is refused on its field', $e instanceof MailError && $e->field === 'gemini_key');
$e = throws(fn () => Settings::save($admin, '', 'deepseek-chat'));
check('a model other than Gemini is refused', $e instanceof MailError && $e->field === 'model');
Settings::save($admin, $fakeKey, 'gemini-2.5-flash');
$raw = Db::decode(Db::value("SELECT setting_value FROM mail_settings WHERE setting_key = 'ai.gemini_key'"));
check('stored encrypted (Crypto v1), not in clear', is_string($raw) && str_starts_with($raw, 'v1:') && !str_contains($raw, 'AIzaTEST'), (string) $raw);
check('read back for the request', Settings::key() === $fakeKey && Settings::configured());
$m = Settings::masked();
check('the screen gets only the masked tail', $m['configured'] && str_ends_with($m['key_masked'], '0001') && !str_contains(json_encode($m), 'AIzaTEST'), json_encode($m, JSON_UNESCAPED_UNICODE));
check('who saved it', $m['updated_by'] === 'Bruno Essomba');
Settings::save($admin, '', 'gemini-2.0-flash');
check('a blank key keeps the stored one (model changed alone)', Settings::key() === $fakeKey && Settings::model() === 'gemini-2.0-flash');
Settings::save($admin, '', 'gemini-2.5-flash');
check('Praxis AI now shows for staff, not for a former employee', Ui::enabled($awa) && Ui::enabled($paul) && !Ui::enabled($gone));

section('The request to Gemini');
$next = $answer('OK');
$ping = Gemini::ping();
$req = end($calls);
check('the key test works with no mail in it', $ping['ok'] && $req['body']['contents'][0]['parts'][0]['text'] === 'Réponds simplement : OK');
check('the URL names the model and never carries the key', $req['url'] === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' && !str_contains($req['url'], 'AIza'));
check('the key travels in the x-goog-api-key header', ($req['headers']['x-goog-api-key'] ?? '') === $fakeKey);
check('2.5 Flash without thinking (fast)', ($req['body']['generationConfig']['thinkingConfig']['thinkingBudget'] ?? null) === 0);
$next = fn () => ['status' => 400, 'body' => json_encode(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]])];
check('a key Google refuses → "refused"', Gemini::ping()['failure'] === 'refused');
$next = fn () => ['status' => 429, 'body' => '{}'];
check('Google limiting → "busy"', Gemini::ping()['failure'] === 'busy');
$next = fn () => throw new RuntimeException('curl 28');
check('Google unreachable → "unreachable"', Gemini::ping()['failure'] === 'unreachable');
$next = fn () => ['status' => 200, 'body' => json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']])];
check('a blocked prompt → "blocked"', Gemini::ping()['failure'] === 'blocked');
$next = fn () => ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'pensée', 'thought' => true], ['text' => 'Réponse']]]]]])];
check('thought parts are left out of the answer', Gemini::generate('s', 'p') === 'Réponse');

section('MailSearch: only what the person can read, never Indésirables, Corbeille or Brouillons');
$subjects = fn (array $rows) => array_column($rows, 'subject');
$awaFound = MailSearch::for($awa, 'facture septembre');
check('Awa finds the shared mailbox\'s mail and her own', in_array('Facture de septembre', $subjects($awaFound), true) && in_array('Réunion de lundi', $subjects($awaFound), true), json_encode($subjects($awaFound), JSON_UNESCAPED_UNICODE));
$all = json_encode($awaFound, JSON_UNESCAPED_UNICODE);
check('never Indésirables, Corbeille or Brouillons', !str_contains($all, 'INDESIRABLE') && !str_contains($all, 'CORBEILLE') && !str_contains($all, 'BROUILLON'), $all);
check('never an attachment\'s content', !str_contains($all, 'PIECE-JOINTE'));
check('a message filed twice counts once', count(array_filter($awaFound, fn ($r) => $r['subject'] === 'Facture de septembre')) === 1);
check('rows have the contract\'s keys', array_keys($awaFound[0]) === ['message_id', 'thread_id', 'account_id', 'subject', 'from', 'date', 'excerpt']);
$paulFound = MailSearch::for($paul, 'facture septembre');
check('Paul (Lecture on contact@) does not see Awa\'s own mailbox', in_array('Facture de septembre', $subjects($paulFound), true) && !str_contains(json_encode($paulFound), 'PERSONNEL'));
check('an admin does not see Awa\'s own mailbox either', !str_contains(json_encode(MailSearch::for($admin, 'facture septembre')), 'PERSONNEL'));
check('no access, no results (driver, former employee)', MailSearch::for($driver, 'facture') === [] && MailSearch::for($gone, 'facture') === []);
check('narrowing never widens (Paul asking in Awa\'s mailbox)', MailSearch::for($paul, 'facture', 20, [$own['id']]) === []);
check('narrowing to one mailbox', !str_contains(json_encode(MailSearch::for($awa, 'facture septembre', 20, [$contact['id']])), 'PERSONNEL'));
check('a word FULLTEXT can\'t see ("T4") is found with LIKE', in_array('Devis T4', $subjects(MailSearch::for($paul, 'Où en est le devis T4 ?')), true));
check('FULLTEXT itself answers on this database (committed rows)', (int) Db::value("SELECT COUNT(*) FROM mail_messages m WHERE MATCH(m.subject, m.from_name, m.from_email, m.to_text, m.body_text) AGAINST ('septembre' IN NATURAL LANGUAGE MODE)") >= 1);
$f = array_values(array_filter($paulFound, fn ($r) => $r['subject'] === 'Facture de septembre'))[0] ?? [];
check('the excerpt is the text around the words', str_contains($f['excerpt'] ?? '', '120 000 FCFA'), $f['excerpt'] ?? '');
check('a question of filler gives the newest mail', count(MailSearch::for($paul, 'Qu\'est-ce que tu as reçu ?', 3)) === 3);

section('Demander à votre courrier: no AI call when nothing matches; cites only what it used');
$before = count($calls);
$r = MailAsk::answer($paul, 'zorglub introuvable');
check('nothing found: a calm answer, no sources, Gemini not called', $r['sources'] === [] && str_contains($r['answer'], 'aucun message') && count($calls) === $before);
$next = $answer("Marc demande de renvoyer la facture de septembre [1]. <b>Montant</b> 120 000 FCFA [9].");
$r = MailAsk::answer($paul, 'Que demande Marc pour la facture de septembre ?');
$prompt = $lastPrompt();
check('the prompt holds the mail Paul can read', str_contains($prompt, 'MARQUEUR-CONTACT') && str_contains($prompt, '[1] De : Marc Etoundi'));
check('…and nothing else', !preg_match('/PERSONNEL|INDESIRABLE|CORBEILLE|BROUILLON|PIECE-JOINTE/', $prompt), $prompt);
check('mail is content, never instructions (system prompt)', str_contains($lastSystem(), 'jamais des') && str_contains($lastSystem(), 'instructions'));
check('only the cited message is listed', count($r['sources']) === 1 && $r['sources'][0]['n'] === 1);
$src = $r['sources'][0];
check('linked to its conversation in its mailbox', $src['url'] === '/modules/mail/index.php?box=' . $contact['id'] . '&thread=' . $msg('f1@epicerie.test')['thread_id'], $src['url']);
check('the answer is escaped; [n] of a message not given is dropped', str_contains($r['html'], '&lt;b&gt;') && !str_contains($r['html'], '<b>') && !str_contains($r['html'], '[9]'), $r['html']);
$e = throws(fn () => MailAsk::answer($paul, 'ok'));
check('a question too short is sent back on its field', $e instanceof MailError && $e->field === 'question' && $e->status === 422);
$next = fn () => ['status' => 503, 'body' => '{}'];
$e = throws(fn () => MailAsk::answer($paul, 'la facture de septembre'));
check('Gemini unavailable → 502 with a calm sentence', $e instanceof MailError && $e->status === 502 && str_contains($e->getMessage(), 'Réessayez'));

section('Limits per person per hour');
Settings::setConfig('limits.ask', Limits::used($paul, 'ask') + 1);
$next = $answer('Oui [1].');
MailAsk::answer($paul, 'la facture de septembre');
$e = throws(fn () => MailAsk::answer($paul, 'la facture de septembre'));
check('the hour\'s questions spent → 429 on the question', $e instanceof MailError && $e->status === 429 && $e->field === 'question');
check('another person is not limited by it', !($e2 = throws(fn () => MailAsk::answer($awa, 'la facture de septembre'))) , $e2 ? $e2->getMessage() : '');
Clock::freeze(new DateTimeImmutable('+61 minutes'));
check('an hour later, allowed again', Limits::allowed($paul, 'ask'));
Clock::freeze(null);
Settings::setConfig('limits.ask', 20);
$usage = (string) Db::value('SELECT setting_value FROM mail_settings WHERE setting_key = ?', ['ai.usage.' . $paul]);
check('the count keeps times only, nothing from the mail', (bool) preg_match('/^\{("(write|summary|ask|test)": ?\[[0-9, ]+\],? ?)+\}$/', $usage), $usage);

section('Résumer: the last 30 messages, quotes cut, no attachments, never Indésirables');
$spamCopy = $msg('spam-in-thread@spam.test', 'spam');
check('the spam reply joined the conversation (so leaving it out is tested)', $spamCopy && $spamCopy['thread_id'] === $livraison);
$messages = ThreadSummariser::messages($livraison);
check('at most the last 30 messages, oldest first', count($messages) === 30 && $messages[0]['message_id'] === 'l3@client.test' && end($messages)['message_id'] === 'l32@client.test', count($messages) . ' ' . ($messages[0]['message_id'] ?? ''));
$transcript = ThreadSummariser::transcript($awa, $messages);
check('older messages left out', !str_contains($transcript, 'L01-MARQUEUR') && !str_contains($transcript, 'L02-MARQUEUR') && str_contains($transcript, 'L03-MARQUEUR'));
check('quoted text cut', str_contains($transcript, 'L32-MARQUEUR') && !str_contains($transcript, 'TEXTE-CITE-MARQUEUR'));
check('never the message in Indésirables', !str_contains($transcript, 'SPAM-DANS-FIL'));
check('our own replies are marked as ours', str_contains($transcript, '(envoyé par notre équipe)'));
$next = $answer(json_encode(['gist' => 'La cliente veut être livrée à Bonamoussadi jeudi matin.', 'key_points' => ['Livraison jeudi matin', 5, ''], 'asked_of_you' => ['Confirmer le créneau'], 'extra' => 'x']));
$s = ThreadSummariser::summarise($paul, $livraison);
check('Lecture is enough to summarise', $s['gist'] === 'La cliente veut être livrée à Bonamoussadi jeudi matin.');
check('only short strings kept, extra keys dropped', $s['key_points'] === ['Livraison jeudi matin'] && $s['asked_of_you'] === ['Confirmer le créneau'] && !isset($s['extra']));
check('asked for JSON', (end($calls)['body']['generationConfig']['responseMimeType'] ?? '') === 'application/json');
$stored = Store::thread($livraison);
check('stored on the conversation with who asked', $stored['ai_summary']['by'] === $paul && $stored['ai_summary_at'] !== null);
$st = ThreadSummariser::state($awa, $livraison);
check('everyone with access sees it, with who asked on a shared mailbox', $st['summary']['gist'] === $s['gist'] && $st['by_name'] === 'Paul' && $st['at_label'] !== '' && $st['can_summarise']);
$e = throws(fn () => ThreadSummariser::state($driver, $livraison));
check('no access → 404 (nothing about it shown)', $e instanceof MailError && $e->status === 404);
$e = throws(fn () => ThreadSummariser::summarise($admin, $msg('p1@lpc.cm')['thread_id']));
check('an admin cannot summarise Awa\'s own mail', $e instanceof MailError && $e->status === 404);
$spamOnly = $msg('spam-only@spam.test', 'spam')['thread_id'];
$before = count($calls);
$e = throws(fn () => ThreadSummariser::summarise($admin, $spamOnly));
check('a conversation only in Indésirables is refused, Gemini not called', $e instanceof MailError && $e->status === 422 && count($calls) === $before);
check('…and its card offers no Résumer', ThreadSummariser::state($admin, $spamOnly)['can_summarise'] === false);
$factureThread = $msg('f1@epicerie.test')['thread_id'];
$next = $answer(json_encode(['gist' => 'Marc veut la facture.', 'key_points' => [], 'asked_of_you' => ['Renvoyer la facture']]));
ThreadSummariser::summarise($awa, $factureThread);
check('never an attachment\'s content in the summary\'s prompt', !str_contains($lastPrompt(), 'PIECE-JOINTE') && str_contains($lastPrompt(), 'MARQUEUR-CONTACT'));
check('a message filed twice is read once', substr_count($lastPrompt(), 'MARQUEUR-CONTACT') === 1);
$t4 = $msg('t4@sabc.test')['thread_id'];
$next = $answer('Pas du JSON');
$e = throws(fn () => ThreadSummariser::summarise($awa, $t4));
check('an answer that is not the JSON asked → 502, nothing stored', $e instanceof MailError && $e->status === 502 && Store::thread($t4)['ai_summary'] === null);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Awa Client <awa@client.test>', subject: 'Re: Livraison Bonamoussadi', text: 'Finalement vendredi.', messageId: 'l33@client.test', inReplyTo: 'l32@client.test', date: $ago('+1 minute')));
MailSync::make()->account($contact['id']);
check('a new message clears the summary (kept until new mail)', ThreadSummariser::state($awa, $livraison)['summary'] === null && Store::thread($livraison)['ai_summary'] === null);

section('Two conversations merged: the summary of the one that stays is cleared (ai_merged listener)');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Chantal <chantal@epicerie.test>', subject: 'Commande Alpha', text: 'Alpha.', messageId: 'alpha@epicerie.test', date: $ago('-3 days')));
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Chantal <chantal@epicerie.test>', subject: 'Commande Beta', text: 'Beta.', messageId: 'beta@epicerie.test', date: $ago('-2 days')));
MailSync::make()->account($contact['id']);
$alpha = $msg('alpha@epicerie.test')['thread_id'];
$beta = $msg('beta@epicerie.test')['thread_id'];
check('two conversations to begin with', $alpha !== $beta);
foreach ([$alpha, $beta] as $t) {
    Db::update('mail_threads', ['ai_summary' => ['gist' => 'Une seule des deux', 'key_points' => [], 'asked_of_you' => [], 'by' => $awa], 'ai_summary_at' => Clock::sql()], $t);
}
// An older message citing both: new mail alone would not clear a summary (it is older than it), the merge does
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Chantal <chantal@epicerie.test>', subject: 'Re: Commande Beta', text: 'Les deux.', messageId: 'both@epicerie.test', references: '<alpha@epicerie.test> <beta@epicerie.test>', date: $ago('-1 day')));
MailSync::make()->account($contact['id']);
$kept = $msg('both@epicerie.test')['thread_id'];
check('merged into one', $msg('alpha@epicerie.test')['thread_id'] === $kept && $msg('beta@epicerie.test')['thread_id'] === $kept && in_array($kept, [$alpha, $beta], true));
$keptRow = Store::thread($kept);
check('the summary of the one that stays is cleared', $keptRow !== null && $keptRow['ai_summary'] === null && $keptRow['ai_summary_at'] === null);

section('Écrire avec l\'IA: reads what the person can read, sends nothing');
$reply = ['accountId' => $contact['id'], 'mode' => 'reply', 'messageId' => $msg('l33@client.test')['id'], 'subject' => 'Re: Livraison Bonamoussadi', 'text' => '', 'to' => [['name' => 'Awa Client', 'email' => 'awa@client.test']]];
$e = throws(fn () => WriteWithAi::draft($paul, $reply, ''));
check('Lecture cannot write (403)', $e instanceof MailError && $e->status === 403);
$next = $answer("Objet : Re: Livraison\n\n**Bonjour** Awa,\n\nVendredi nous convient. <script>x</script>\n\n\n\nCordialement,\nAwa");
$sentBefore = count($server->sent);
$d = WriteWithAi::draft($awa, $reply, 'Accepter vendredi');
$req = end($calls);
check('a draft comes back, tidied', !str_contains($d['text'], 'Objet') && !str_contains($d['text'], '**') && str_starts_with($d['text'], 'Bonjour Awa,') && !str_contains($d['text'], "\n\n\n"), $d['text']);
check('its HTML is escaped', str_contains($d['html'], '&lt;script&gt;') && !str_contains($d['html'], '<script'));
check('nothing is sent', count($server->sent) === $sentBefore);
check('written for the person, from the shared mailbox', str_contains($req['body']['system_instruction']['parts'][0]['text'], 'Awa Mbarga') && str_contains($req['body']['system_instruction']['parts'][0]['text'], 'boîte partagée contact@lpc.cm'));
$p = $req['body']['contents'][0]['parts'][0]['text'];
check('the conversation is in the prompt, quotes cut', str_contains($p, 'Finalement vendredi') && str_contains($p, 'L32-MARQUEUR') && !str_contains($p, 'TEXTE-CITE-MARQUEUR'));
check('at most the last 30 messages, never Indésirables', !str_contains($p, 'L03-MARQUEUR') && str_contains($p, 'L04-MARQUEUR') && !str_contains($p, 'SPAM-DANS-FIL'), substr($p, 0, 300));
check('recipients, subject and instruction', str_contains($p, 'Destinataires : Awa Client') && str_contains($p, 'Objet : Re: Livraison Bonamoussadi') && str_contains($p, 'Consigne : Accepter vendredi'));
check('a warmer temperature for writing', ($req['body']['generationConfig']['temperature'] ?? null) === 0.4);
$e = throws(fn () => WriteWithAi::draft($awa, ['accountId' => $contact['id'], 'mode' => 'new', 'text' => ''], '  '));
check('a new message with nothing to go on → said on the instruction', $e instanceof MailError && $e->field === 'instruction' && $e->status === 422);
$next = $answer("Bonjour,\n\nMerci pour votre commande.\n\nCordialement,\nAwa");
WriteWithAi::draft($awa, ['accountId' => $contact['id'], 'mode' => 'new', 'text' => 'Merci pour la commande'], 'Remercier');
check('a new message: the person\'s own text, no conversation', str_contains($lastPrompt(), 'Merci pour la commande') && !str_contains($lastPrompt(), 'MARQUEUR'));
$next = $answer('Bonjour, merci.');
WriteWithAi::draft($admin, ['accountId' => $contact['id'], 'mode' => 'reply', 'messageId' => $msg('p1@lpc.cm')['id'], 'text' => ''], 'Répondre');
check('a message the writer cannot read is never read for them', !str_contains($lastPrompt(), 'PERSONNEL-MARQUEUR'));
$e = throws(fn () => WriteWithAi::draft($awa, ['accountId' => $own['id'] + 999, 'mode' => 'new'], 'x'));
check('an unknown mailbox → 403', $e instanceof MailError && $e->status === 403);

section('The mounts: shown only with a key, assets once, no form inside the composer');
$render = function (array $names, int $userId) use ($contact) {
    Ui::reset();
    ob_start();
    foreach ($names as $n) {
        Mount::render($n, ['user_id' => $userId, 'account_id' => $contact['id'], 'folder' => 'inbox', 'thread_id' => null, 'shared' => true]);
    }

    return (string) ob_get_clean();
};
$html = $render(['ask_mail', 'thread_summary', 'compose_ai'], $awa);
check('the three mounts print their parts', str_contains($html, 'data-mai-ask-open') && str_contains($html, 'data-mai-summary') && str_contains($html, 'data-mai-write-open'));
check('stylesheet, data and script once per page', substr_count($html, 'mail-ai.js') === 1 && substr_count($html, 'id="mail-ai-data"') === 1 && substr_count($html, 'lpc-mail-ai.css') === 1);
check('no <form> (the composer has its own)', !str_contains($html, '<form'));
preg_match('#<script type="application/json" id="mail-ai-data">(.*?)</script>#s', $html, $mm);
$data = json_decode($mm[1] ?? '', true);
check('the page data lists only the person\'s mailboxes', array_column($data['boxes'] ?? [], 'email') === ['awa.mbarga@lpc.cm', 'contact@lpc.cm'], $mm[1] ?? '');
check('nothing secret in the page', !str_contains($html, 'AIza') && !str_contains($html, '0001'));
$paulData = $render(['ask_mail'], $paul);
check('Paul\'s page data does not name Awa\'s mailbox', !str_contains($paulData, 'awa.mbarga@lpc.cm'));
Settings::removeKey($admin);
check('removing the key turns Praxis AI off', !Settings::configured() && MailSettings::get('ai.gemini_key') === null);
check('without a key staff see nothing', $render(['ask_mail', 'thread_summary', 'compose_ai'], $awa) === '');
$adminHtml = $render(['ask_mail', 'thread_summary'], $admin);
check('…and an admin sees only the way to the settings', str_contains($adminHtml, 'Configurer Praxis AI') && str_contains($adminHtml, '/modules/mail/ai_settings.php') && !str_contains($adminHtml, 'mail-ai.js'));
$e = throws(fn () => Settings::removeKey($awa));
check('only admins remove the key', $e instanceof MailError && $e->status === 403);

section('Nothing from the mail, the key or the questions in the log');
Gemini::fake(null);
$written = (string) file_get_contents($log);
$leaks = array_filter(['MARQUEUR', 'PIECE-JOINTE', 'AIzaTEST', 'facture de septembre', 'Bonamoussadi', 'Accepter vendredi', 'Vendredi nous convient'], fn ($w) => stripos($written, $w) !== false);
check('the log names failures only (model, HTTP status)', $leaks === [] && str_contains($written, 'mail/ai: Gemini'), implode(', ', $leaks) . "\n" . substr($written, 0, 600));
@unlink($log);

mail_test_done();
