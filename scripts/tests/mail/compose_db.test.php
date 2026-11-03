<?php
/**
 * scripts/tests/mail/compose_db.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 3 (writing and signatures), against a real database (CI: MariaDB
 * 10.11) and the in-memory FakeMailServer: migration 320, starting new mail,
 * replies, reply all and forwards (recipients, subject, quote, threading
 * headers, the original's files), access (Lecture cannot write, someone else's
 * draft does not exist for you), saving as you type, the copy in the server's
 * Brouillons, Annuler l'envoi, sending exactly once (copy in Envoyés, sent_by,
 * \Answered, the team timeline), retries, failures and their bell, stuck rows,
 * scheduled mail, uploaded files kept in the database, drafts written in other
 * mail apps, signatures from the HR record and the company part.
 *
 * Run:  MAIL_TEST_DB_PORT=3307 MAIL_TEST_DB_NAME=bureau_mail_test_pr3 php scripts/tests/mail/compose_db.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Clock;
use LPC\Mail\Compose\ComposeConfig;
use LPC\Mail\Compose\Files;
use LPC\Mail\Compose\MessageBuilder;
use LPC\Mail\Compose\Outbox;
use LPC\Mail\Compose\OutboxSender;
use LPC\Mail\Compose\Recipients;
use LPC\Mail\Compose\Signatures;
use LPC\Mail\Db;
use LPC\Mail\Events;
use LPC\Mail\MailError;
use LPC\Mail\MailSettings;
use LPC\Mail\Store;
use LPC\Mail\Sync\MailSync;
use LPC\Mail\Tests\FakeMailServer;

$pdo = mail_test_db();
Events::reset([]);

section('Migration 320');
check('mail_signatures and mail_compose_files exist (applied twice)', (int) Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('mail_signatures', 'mail_compose_files')") === 2);
check('a file’s pieces go with their outbox row (foreign key)', (bool) Db::value("SELECT 1 FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_mail_compose_files_outbox' AND delete_rule = 'CASCADE'"));

$admin = mail_test_user('Bruno Essomba', 'admin');
$awa = mail_test_user('Awa Mbarga', 'accountant');
$paul = mail_test_user('Paul Ndi', 'accountant');

$server = FakeMailServer::install();
$server->addMailbox('contact@lpc.cm', 'secret');
$server->addMailbox('awa.mbarga@lpc.cm', 'secret');
$contact = mail_test_account('contact@lpc.cm', 'shared', $admin, 'secret', 'LPC Service client');
mail_test_member($contact, $awa, 'send');
mail_test_member($contact, $paul, 'read');
$own = mail_test_account('awa.mbarga@lpc.cm', 'personal', $awa, 'secret', 'Awa Mbarga');

$recent = (new DateTimeImmutable('-3 hours'))->format('D, d M Y H:i:s O');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(
    from: 'Marc Etoundi <marc@epicerie.test>', to: 'LPC <contact@lpc.cm>, compta@epicerie.test', cc: 'Paul Ndi <paul@epicerie.test>, contact@lpc.cm',
    subject: 'Facture de septembre', text: "Bonjour,\nmerci de renvoyer la facture.\nMarc", messageId: 'f1@epicerie.test', references: '<f0@epicerie.test>', date: $recent,
    attachments: [['name' => 'relevé.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 relevé']],
));
MailSync::make()->account($contact['id'], null, true);
MailSync::make()->account($own['id'], null, true);
$original = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 'f1@epicerie.test'"));
check('fixture: the original is in bureau with its attachment', $original !== null && $original['has_attachments']);

$outbox = Outbox::make();
$sender = OutboxSender::make();

section('Starting: new mail, reply, reply all, forward');
$new = $outbox->start($awa, 'new', $contact['id'], null, 'mailto:Client <client@test.cm>');
check('new mail from the chosen mailbox, as a draft', $new['account_id'] === $contact['id'] && $new['status'] === 'draft' && $new['kind'] === 'new');
check('a mailto: address fills To', ($new['payload']['to'][0]['email'] ?? '') === 'client@test.cm');
check('its Message-ID is chosen at once, on the mailbox domain', str_ends_with((string) $new['message_id'], '@lpc.cm') && $new['message_id_hash'] === Store::hashId($new['message_id']));
check('new mail carries the full signature', $new['payload']['signature'] === 'full');
$default = $outbox->start($awa, 'new');
check('new mail without a mailbox: the person’s first one they can send from (their own)', $default['account_id'] === $own['id']);

$reply = $outbox->start($awa, 'reply', null, $original['id']);
$p = $reply['payload'];
check('reply goes to the sender', array_column($p['to'], 'email') === ['marc@epicerie.test'] && $p['cc'] === []);
check('reply subject gets “RE : ”', $p['subject'] === 'RE : Facture de septembre', $p['subject']);
check('reply threads: In-Reply-To and References', $p['in_reply_to'] === 'f1@epicerie.test' && $p['references'] === 'f0@epicerie.test f1@epicerie.test', (string) $p['references']);
check('reply quotes the original (“a écrit :”) and starts with the short signature', str_contains((string) $p['quote_html'], 'a écrit') && $p['quote'] === true && $p['signature'] === 'slim');
check('quoted text is escaped and marked with > in plain text', str_contains((string) $p['quote_text'], '> merci de renvoyer la facture.'));
check('reply keeps the conversation and the original', $reply['thread_id'] === $original['thread_id'] && $reply['reply_to_message_id'] === $original['id']);

$all = $outbox->start($awa, 'reply_all', null, $original['id']);
check('reply all: everyone else in Cc, never the mailbox itself', array_column($all['payload']['cc'], 'email') === ['compta@epicerie.test', 'paul@epicerie.test'], json_encode($all['payload']['cc']));

$fwd = $outbox->start($awa, 'forward', null, $original['id']);
check('forward: no recipients, “TR : ”, no threading headers', $fwd['payload']['to'] === [] && $fwd['payload']['subject'] === 'TR : Facture de septembre' && $fwd['payload']['in_reply_to'] === null);
check('forward carries the original’s file', ($fwd['payload']['attachments'][0]['source'] ?? '') === 'original' && ($fwd['payload']['attachments'][0]['name'] ?? '') === 'relevé.pdf');
check('forward header in French', str_contains((string) $fwd['payload']['quote_text'], 'Message transféré') && str_contains((string) $fwd['payload']['quote_text'], 'Objet : Facture de septembre'));

section('Access');
$e = throws(fn () => $outbox->start($paul, 'reply', null, $original['id']));
check('Lecture cannot write from the mailbox (403, plain French)', $e instanceof MailError && $e->status === 403 && str_contains($e->getMessage(), 'Lecture et envoi'));
$e = throws(fn () => $outbox->start($paul, 'new', $own['id']));
check('nobody writes from someone else’s personal mailbox', $e instanceof MailError && $e->status === 403);
$server->addMailbox('ventes@lpc.cm', 'secret');
$ventes = mail_test_account('ventes@lpc.cm', 'shared', $admin, 'secret', 'LPC Ventes');
mail_test_member($ventes, $awa, 'read');
$fallback = $outbox->start($awa, 'new', $ventes['id']);
check('new mail from a mailbox Awa only reads goes from one she can write from', $fallback['account_id'] !== $ventes['id'] && in_array($fallback['account_id'], [$own['id'], $contact['id']], true));
Db::exec('DELETE FROM mail_outbox WHERE id = ?', [$fallback['id']]);
$e = throws(fn () => $outbox->save($admin, $reply['id'], ['subject' => 'x']));
check('someone else’s draft does not exist for you (404)', $e instanceof MailError && $e->status === 404);
$e = throws(fn () => $outbox->own($awa, 'not-a-uuid', ['draft']));
check('a malformed id is not found', $e instanceof MailError && $e->status === 404);

section('Saving as you type');
$r = $outbox->save($awa, $reply['id'], [
    'to' => [['name' => 'Marc', 'email' => 'MARC@epicerie.test'], 'pas une adresse', 'Ada <ada@client.test>'],
    'cc' => 'Bob <bob@client.test>; bob@client.test',
    'subject' => "RE : Facture\nde septembre",
    'html' => '<p onclick="x()">Bonjour <b>Marc</b>,</p><script>alert(1)</script><p><a href="javascript:alert(1)">ici</a> et <a href="https://lpc.cm">notre site</a></p>',
    'signature' => 'full',
]);
$p = $r['row']['payload'];
check('addresses kept lower case, once each; the bad one reported, not kept', array_column($p['to'], 'email') === ['marc@epicerie.test', 'ada@client.test'] && $r['invalid'] === ['to' => ['pas une adresse']], json_encode($r['invalid']));
check('Cc typed as text with a duplicate', array_column($p['cc'], 'email') === ['bob@client.test']);
check('subject on one line', $p['subject'] === 'RE : Facture de septembre');
check('the editor’s HTML is cleaned (no script, no handler, no javascript: link)', !str_contains($p['html'], 'script') && !str_contains($p['html'], 'onclick') && !str_contains($p['html'], 'javascript') && str_contains($p['html'], '<a href="https://lpc.cm">'), $p['html']);
check('the draft is marked as written in', $p['touched'] === true);

$r = $outbox->save($awa, $new['id'], ['account_id' => $own['id']]);
check('changing the From mailbox gives a new Message-ID on that mailbox', $r['row']['account_id'] === $own['id'] && $r['row']['message_id'] !== $new['message_id']);
$e = throws(fn () => $outbox->save($paul, $outbox->start($paul, 'new', null)['id'] ?? '', []));
check('Paul has no mailbox to write from (403)', $e instanceof MailError && $e->status === 403, $e ? $e->getMessage() : 'no error');
$e = throws(fn () => $outbox->save($awa, $new['id'], ['account_id' => $contact['id'] + 999]));
check('an unknown From mailbox is refused on its field', $e instanceof MailError && $e->field === 'account_id');

section('The copy in the server’s Brouillons');
check('saved on the server', $outbox->saveServerDraft($awa, $reply['id']));
$drafts = $server->folder('contact@lpc.cm', 'INBOX.Drafts');
$first = array_key_last($drafts);
check('one copy, \\Draft and \\Seen', count($drafts) === 1 && in_array('\\Draft', $drafts[$first]['flags'], true), json_encode(array_map(fn ($m) => $m['flags'], $drafts)));
check('the draft copy carries the same Message-ID and no signature', str_contains($drafts[$first]['raw'], '<' . $reply['message_id'] . '>') && !str_contains($drafts[$first]['raw'], 'Mobile'));
$outbox->save($awa, $reply['id'], ['html' => '<p>Bonjour Marc, voici la facture.</p>']);
$outbox->saveServerDraft($awa, $reply['id']);
$drafts = $server->folder('contact@lpc.cm', 'INBOX.Drafts');
check('saving again replaces the copy (still one)', count($drafts) === 1 && array_key_last($drafts) !== $first);
check('the row knows where its copy is', (Outbox::row($reply['id'])['payload']['server_draft']['uid'] ?? 0) === array_key_last($drafts));

section('Draft from the server’s Brouillons');
MailSync::make()->roles($contact['id'], ['drafts']);
$copy = (int) Db::value("SELECT m.id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE f.role = 'drafts' AND m.account_id = ? AND m.message_id_hash = ?", [$contact['id'], $reply['message_id_hash']]);
check('bureau’s own draft is found again by its Message-ID', $copy > 0 && $outbox->fromServerDraft($awa, $copy)['id'] === $reply['id']);
$e = throws(fn () => $outbox->fromServerDraft($admin, $copy));
check('nobody else takes over a draft being written', $e instanceof MailError && $e->status === 403);
$server->deliver('contact@lpc.cm', 'INBOX.Drafts', FakeMailServer::message(from: 'contact@lpc.cm', to: 'Lucie <lucie@client.test>', subject: 'Brouillon du téléphone', text: "Écrit sur\nle téléphone", messageId: 'phone-draft@lpc.cm'), ['\\Draft', '\\Seen']);
MailSync::make()->roles($contact['id'], ['drafts']);
$phone = (int) Db::value("SELECT id FROM mail_messages WHERE message_id = 'phone-draft@lpc.cm'");
$elsewhere = $outbox->fromServerDraft($awa, $phone);
check('a draft from another mail app becomes a bureau draft (no extra signature, its copy remembered)', $elsewhere['payload']['signature'] === 'none'
    && $elsewhere['payload']['subject'] === 'Brouillon du téléphone' && array_column($elsewhere['payload']['to'], 'email') === ['lucie@client.test']
    && ($elsewhere['payload']['server_draft']['path'] ?? '') === 'INBOX.Drafts' && str_contains($elsewhere['payload']['html'], 'le téléphone'));
check('…with a new Message-ID of its own', $elsewhere['message_id'] !== 'phone-draft@lpc.cm');

section('Discarding');
check('discard', $outbox->discard($awa, $elsewhere['id']));
check('the row is gone, and the copy on the server with it', Outbox::row($elsewhere['id']) === null && !array_filter($server->folder('contact@lpc.cm', 'INBOX.Drafts'), fn ($m) => str_contains($m['raw'], 'phone-draft@lpc.cm')));
check('bureau’s copy of it goes at once', !Db::value('SELECT 1 FROM mail_messages WHERE id = ?', [$phone]));

section('Annuler l’envoi');
$empty = $outbox->start($awa, 'new', $contact['id']);
$e = throws(fn () => $outbox->queue($awa, $empty['id']));
check('no recipient: refused on To', $e instanceof MailError && $e->field === 'to');
$q = $outbox->queue($awa, $reply['id']);
check('queued for 10 seconds from now', $q['status'] === 'queued' && abs(strtotime($q['send_at']) - strtotime(Clock::sql(10))) <= 2 && $q['payload']['scheduled'] === false);
$e = throws(fn () => $outbox->save($awa, $reply['id'], ['subject' => 'x']));
check('a queued message cannot be changed', $e instanceof MailError && str_contains($e->getMessage(), 'Annulez'));
check('not yet due: the cron leaves it', $sender->due()['sent'] === 0 && Outbox::row($reply['id'])['status'] === 'queued' && !$server->sent);
check('undo puts it back in the composer', $outbox->undo($awa, $reply['id']) && Outbox::row($reply['id'])['status'] === 'draft');
check('undo on a draft does nothing', !$outbox->undo($awa, $reply['id']));

section('Sending exactly once');
$outbox->queue($awa, $reply['id']);
$row = $sender->send($reply['id'], 15);   // the composer, at the end of the undo window
check('sent', $row['status'] === 'sent' && $row['sent_at'] !== null && $row['attempts'] === 1, json_encode([$row['status'], $row['last_error']]));
check('one SMTP message', count($server->sent) === 1);
$raw = $server->sent[0]['raw'];
check('From is the shared mailbox (decision 7)', str_contains($raw, 'From: LPC Service client <contact@lpc.cm>'));
check('In-Reply-To and References on the wire', str_contains($raw, 'In-Reply-To: <f1@epicerie.test>') && str_contains($raw, 'References: <f0@epicerie.test> <f1@epicerie.test>'));
check('same Message-ID as the draft', str_contains($raw, 'Message-ID: <' . $reply['message_id'] . '>'));
$html = quoted_printable_decode($raw);
check('the writer’s signature is in it (name, shared address)', str_contains($html, 'Awa Mbarga') && str_contains($html, 'mailto:contact@lpc.cm'));
check('the quoted original follows', str_contains($html, 'a écrit'));
check('a plain-text part with the "-- " signature line', str_contains($html, "\r\n-- \r\n") || str_contains($html, "\n-- \n"));
check('again: nothing more goes (already sent)', $sender->send($reply['id'], 15)['status'] === 'sent' && count($server->sent) === 1);
check('the cron does not send it again either', $sender->due()['sent'] === 0 && count($server->sent) === 1);
$sentCopies = $server->folder('contact@lpc.cm', 'INBOX.Sent');
check('a copy in Envoyés', count(array_filter($sentCopies, fn ($m) => str_contains($m['raw'], $reply['message_id']))) === 1);
$copy = Db::one("SELECT m.sent_by, m.thread_id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE f.role = 'sent' AND m.message_id_hash = ?", [$reply['message_id_hash']]);
check('read back at once, credited to Awa (sent_by), in the same conversation', $copy && (int) $copy['sent_by'] === $awa && (int) $copy['thread_id'] === $original['thread_id'], json_encode($copy));
check('the original is \\Answered on the server and in bureau', in_array('\\Answered', $server->folder('contact@lpc.cm', 'INBOX')[$original['uid']]['flags'], true) && Store::message($original['id'])['is_answered']);
check('the copy in Brouillons is gone', !array_filter($server->folder('contact@lpc.cm', 'INBOX.Drafts'), fn ($m) => str_contains($m['raw'], $reply['message_id'])));
$act = Db::one("SELECT * FROM mail_thread_activity WHERE thread_id = ? AND type = 'sent'", [$original['thread_id']]);
check('the team timeline records it (shared mailbox)', $act && (int) $act['user_id'] === $awa && (Db::decode($act['data'])['outbox_id'] ?? '') === $reply['id']);

$claimA = Outbox::make()->start($awa, 'new', $contact['id'], null, 'x@client.test');
Outbox::make()->queue($awa, $claimA['id']);
Db::exec("UPDATE mail_outbox SET status = 'sending' WHERE id = ?", [$claimA['id']]);   // another run took it first
$before = count($server->sent);
check('a row another run has taken is left alone', $sender->send($claimA['id'], 30)['status'] === 'sending' && count($server->sent) === $before);
Db::exec('DELETE FROM mail_outbox WHERE id = ?', [$claimA['id']]);

section('Retries, failures, stuck rows');
$t = $outbox->start($awa, 'new', $contact['id'], null, 'client@test.cm');
$outbox->save($awa, $t['id'], ['subject' => 'Livraison', 'html' => '<p>Bonjour</p>']);
$outbox->queue($awa, $t['id']);
$server->smtpFailure = 'temporary';
$row = $sender->send($t['id'], 15);
check('a temporary failure waits 1 minute', $row['status'] === 'queued' && $row['attempts'] === 1 && abs(strtotime($row['send_at']) - strtotime(Clock::sql(60))) <= 2 && $row['last_error'] !== null);
Db::exec('UPDATE mail_outbox SET send_at = ?, attempts = 6 WHERE id = ?', [Clock::sql(-1), $t['id']]);
$server->smtpFailure = 'temporary';
check('after the last retry it is not sent', $sender->due()['failed'] === 1 && Outbox::row($t['id'])['status'] === 'failed');
$bell = Db::one("SELECT * FROM notifications WHERE user_id = ? AND title = 'Message non envoyé' ORDER BY id DESC LIMIT 1", [$awa]);
check('the writer is told, with a link to Programmés et non envoyés', $bell && $bell['type'] === 'error' && $bell['related_url'] === '/modules/mail/compose_outbox.php' && str_contains($bell['message'], '« Livraison »'));
$reopened = $outbox->open($awa, $t['id']);
check('a message that did not go opens again as a draft', $reopened['status'] === 'draft');
$outbox->queue($awa, $t['id']);
$server->smtpFailure = 'rejected';
check('a permanent refusal fails at once', $sender->send($t['id'], 15)['status'] === 'failed');
$outbox->open($awa, $t['id']);
$outbox->queue($awa, $t['id']);
Db::exec("UPDATE mail_outbox SET status = 'sending', updated_at = ? WHERE id = ?", [Clock::sql(-20 * 60), $t['id']]);
$n = count($server->sent);
check('a row stuck in “sending” is marked not sent, never resent', $sender->due()['stuck'] === 1 && Outbox::row($t['id'])['status'] === 'failed' && count($server->sent) === $n);
check('…and says to look in Envoyés first', str_contains((string) Outbox::row($t['id'])['last_error'], 'Envoyés'));

Db::exec("UPDATE mail_accounts SET status = 'needs_reconnect' WHERE id = ?", [$contact['id']]);
$outbox->open($awa, $t['id']);
$outbox->queue($awa, $t['id']);
check('a mailbox waiting for its password: not sent, said plainly', $sender->send($t['id'], 15)['status'] === 'failed' && str_contains((string) Outbox::row($t['id'])['last_error'], 'mot de passe'));
Db::exec("UPDATE mail_accounts SET status = 'active' WHERE id = ?", [$contact['id']]);

section('Programmer');
$s = $outbox->start($awa, 'new', $own['id'], null, 'client@test.cm');
$e = throws(fn () => $outbox->queue($awa, $s['id'], Clock::now()->modify('+20 seconds')));
check('a time less than a minute away is refused on its field', $e instanceof MailError && $e->field === 'send_at');
$at = Clock::now()->modify('+2 hours');
$q = $outbox->queue($awa, $s['id'], $at);
check('scheduled', $q['status'] === 'queued' && $q['payload']['scheduled'] === true && $q['send_at'] === Clock::format($at));
$n = count($server->sent);
$sender->due();
check('not before its time', Outbox::row($s['id'])['status'] === 'queued' && count($server->sent) === $n);
Clock::freeze(Clock::now()->modify('+2 hours +1 minute'));
check('at its time, the cron sends it', $sender->due()['sent'] === 1 && Outbox::row($s['id'])['status'] === 'sent');
Clock::freeze(null);
check('from Awa’s own mailbox', str_contains(end($server->sent)['raw'], 'From: Awa Mbarga <awa.mbarga@lpc.cm>'));
$s2 = $outbox->start($awa, 'new', $own['id'], null, 'client@test.cm');
$outbox->queue($awa, $s2['id'], Clock::now()->modify('+1 day'));
check('cancelling a schedule (undo) keeps it as a draft', $outbox->undo($awa, $s2['id']) && !isset(Outbox::row($s2['id'])['payload']['scheduled']));

section('Files');
ComposeConfig::set('chunk_bytes', 1000);
$f = $outbox->start($awa, 'new', $contact['id'], null, 'client@test.cm');
$pdf = '%PDF-1.7 ' . str_repeat('A', 2500);
$a = $outbox->addUpload($awa, $f['id'], 'Devis n°12/2026.pdf', $pdf);
check('an upload is described in the payload (type from its bytes, safe name)', $a['mime'] === 'application/pdf' && $a['name'] === 'Devis n°12 2026.pdf' && $a['size'] === strlen($pdf) && $a['source'] === 'upload');
check('kept in the database in pieces', (int) Db::value('SELECT COUNT(*) FROM mail_compose_files WHERE outbox_id = ?', [$f['id']]) === 3 && Files::get($f['id'], $a['key']) === $pdf);
$b = $outbox->addUpload($awa, $f['id'], 'notes.txt', 'bonjour');
$outbox->removeAttachment($awa, $f['id'], $b['key']);
check('removing a file removes its bytes', !Files::has($f['id'], $b['key']) && count(Outbox::row($f['id'])['payload']['attachments']) === 1);
ComposeConfig::set('max_attachment_bytes', 3000);
$e = throws(fn () => $outbox->addUpload($awa, $f['id'], 'gros.bin', str_repeat('x', 1000)));
check('files over the limit are refused (413, says what to do)', $e instanceof MailError && $e->status === 413 && str_contains($e->getMessage(), 'lien'));
ComposeConfig::reset();
$e = throws(fn () => $outbox->addUpload($awa, $f['id'], 'vide.txt', ''));
check('an empty file is refused', $e instanceof MailError);
$outbox->queue($awa, $f['id']);
$sender->send($f['id'], 15);
$raw = end($server->sent)['raw'];
check('the file goes with the message (RFC 2231 name)', str_contains($raw, "filename*=utf-8''Devis%20n%C2%B012%202026.pdf") && str_contains($raw, base64_encode(substr($pdf, 0, 57))));
check('its bytes are deleted once sent', !Db::value('SELECT 1 FROM mail_compose_files WHERE outbox_id = ?', [$f['id']]));

$fw = $outbox->start($awa, 'forward', null, $original['id']);
$outbox->save($awa, $fw['id'], ['to' => 'Comptable <compta@cabinet.test>']);
$outbox->queue($awa, $fw['id']);
$sender->send($fw['id'], 15);
$raw = end($server->sent)['raw'];
check('a forward sends the original’s file, read from the server', str_contains($raw, base64_encode('%PDF-1.4 relevé')) && Outbox::row($fw['id'])['status'] === 'sent');
check('a forward does not mark the original \\Answered twice or as a reply', Outbox::row($fw['id'])['kind'] === 'forward');

section('Closing the composer');
$untouched = $outbox->start($awa, 'reply', null, $original['id']);
check('an answer with nothing written is not worth keeping', !$outbox->worthKeeping($untouched));
$outbox->save($awa, $untouched['id'], ['html' => '<p>Merci</p>']);
check('…once written in, it is', $outbox->worthKeeping(Outbox::row($untouched['id'])));
check('opening the same answer again carries on with that draft', $outbox->start($awa, 'reply', null, $original['id'])['id'] === $untouched['id']);
$blank = $outbox->start($awa, 'new', $contact['id']);
Db::exec('UPDATE mail_outbox SET updated_at = ? WHERE id = ?', [Clock::sql(-25 * 3600), $blank['id']]);
check('drafts opened and never written in go after a day', $outbox->pruneUntouched() >= 1 && Outbox::row($blank['id']) === null && Outbox::row($untouched['id']) !== null);

section('Programmés et non envoyés');
$list = $outbox->listFor($awa);
check('groups: scheduled, not sent, drafts', count($list['failed']) === 1 && $list['failed'][0]['id'] === $t['id']
    && in_array($untouched['id'], array_column($list['drafts'], 'id'), true) && !in_array($blank['id'], array_column($list['drafts'], 'id'), true), json_encode(array_map(fn ($g) => count($g), $list)));
check('Paul sees nothing (no mailbox he can send from)', $outbox->listFor($paul) === ['scheduled' => [], 'failed' => [], 'drafts' => []]);

section('Signatures: the HR record');
$pdo->exec('ALTER TABLE employees ADD COLUMN department VARCHAR(100) NULL');   // live has it (migration 108); the fixture does not
Db::exec("UPDATE employees e JOIN users u ON u.employee_id = e.id SET e.job_title = 'Comptable', e.department = 'Finance', e.personal_phone = '+237 699 11 22 33' WHERE u.id = ?", [$awa]);
Signatures::flush();
$me = Signatures::person($awa);
check('name, job title, service and phone from HR', $me['name'] === 'Awa Mbarga' && $me['title'] === 'Comptable' && $me['department'] === 'Finance' && $me['mobile'] === '+237 699 11 22 33' && $me['phone'] === null, json_encode($me));
$html = Signatures::html($awa, $contact, 'full');
check('the full signature shows them, the mailbox address and the company', str_contains($html, 'Awa Mbarga') && str_contains($html, 'Comptable · Finance') && str_contains($html, 'tel:+237699112233') && str_contains($html, 'mailto:contact@lpc.cm') && str_contains($html, 'La Petite Cour'));
check('tables and inline styles only (no <style>, no class)', str_contains($html, 'role="presentation"') && !str_contains($html, '<style') && !str_contains($html, 'class='));
check('the logo is a PNG with its size set, alt text the company', (bool) preg_match('#<img src="https?://[^"]+\.png" width="76" height="76" alt="La Petite Cour"#', $html));
check('short signature on one block', str_contains(Signatures::html($awa, $contact, 'slim'), 'border-left:3px solid') && Signatures::html($awa, $contact, 'none') === '');
check('plain text twin starts with "-- "', str_starts_with(Signatures::text($awa, $contact, 'full'), "-- \nAwa Mbarga\nComptable · Finance"));

$e = throws(fn () => Signatures::setNumbers($awa, '+237 abc', null));
check('a number that is not one is refused on its field', $e instanceof MailError && $e->field === 'phone');
Signatures::setNumbers($awa, '+237 233 42 02 81', '');
$me = Signatures::person($awa);
check('own numbers replace HR’s; a blank mobile hides it', $me['phone'] === '+237 233 42 02 81' && $me['mobile'] === null && $me['hr_phone'] === '+237 699 11 22 33');
Db::exec("UPDATE employees e JOIN users u ON u.employee_id = e.id SET e.first_name = '<b>Awa</b>' WHERE u.id = ?", [$awa]);
\LPC\Mail\People::flush();
Signatures::flush();
check('everything from records is escaped', str_contains(Signatures::html($awa, $contact), '&lt;b&gt;Awa&lt;/b&gt;') && !str_contains(Signatures::html($awa, $contact), '<b>Awa'));

section('Signatures: the company part (admins)');
$c = Signatures::company();
check('defaults without a company profile: La Petite Cour, distribution agroalimentaire', $c['name'] === 'La Petite Cour' && $c['tagline'] === 'Distribution agroalimentaire' && $c['custom'] === false);
$pdo->exec("CREATE TABLE company_profile (id INT PRIMARY KEY, legal_name VARCHAR(100), trade_name VARCHAR(100), activity_sector VARCHAR(100), erp_tagline VARCHAR(100),
    address_line1 VARCHAR(100), po_box VARCHAR(50), city VARCHAR(50), country VARCHAR(50), phone_primary VARCHAR(60), website VARCHAR(100), rccm_number VARCHAR(50), niu VARCHAR(50),
    brand_color_primary VARCHAR(7), brand_color_accent VARCHAR(7), is_active TINYINT)");
$pdo->exec("INSERT INTO company_profile VALUES (1, 'La Petite Cour', '', 'Distribution agroalimentaire', '', '1030, Avenue Douala Manga Bell', 'B.P. 5120', 'Douala', 'Cameroun', '+237 696 291 800 / +237 233 42 02 81', 'https://lpc.cm', 'RC/DLA/2019/A/A/687', '', '#005a2b', 'bad', 1)");
Signatures::flush();
$c = Signatures::company();
check('read from the company profile', $c['address'] === '1030, Avenue Douala Manga Bell · B.P. 5120 · Douala, Cameroun' && $c['phone'] === '+237 696 291 800' && $c['website'] === 'https://lpc.cm' && $c['legal'] === 'RCCM RC/DLA/2019/A/A/687', json_encode($c));
check('brand colours checked (bad one falls back to LPC leaf green)', $c['brand'] === '#005A2B' && $c['accent'] === '#8CC63F');
$e = throws(fn () => Signatures::setCompany(['name' => ''], $admin));
check('a name is needed', $e instanceof MailError && $e->field === 'name');
$e = throws(fn () => Signatures::setCompany(['name' => 'LPC', 'logo_url' => 'http://x.test/logo.png'], $admin));
check('the logo must be https', $e instanceof MailError && $e->field === 'logo_url');
$c = Signatures::setCompany(['name' => 'La Petite Cour', 'tagline' => 'Eau minérale pour les entreprises', 'address' => '', 'phone' => '+237 696 291 800', 'website' => 'lpc.cm', 'show_logo' => '1', 'show_legal' => 'true'], $admin);
check('saved: a site without https gets it; an emptied address is hidden', $c['website'] === 'https://lpc.cm' && $c['address'] === '' && $c['show_legal'] === true && $c['custom'] === true);
$html = Signatures::html($awa, $contact);
check('the signature follows (tagline, legal line, no address)', str_contains($html, 'Eau minérale pour les entreprises') && str_contains($html, 'RCCM RC/DLA/2019/A/A/687') && !str_contains($html, 'Manga Bell'));
check('kept as signature.company', is_array(MailSettings::get('signature.company')));
Signatures::resetCompany();
MailSettings::flush();
check('back to the company profile', Signatures::company()['custom'] === false && str_contains(Signatures::company()['address'], 'Manga Bell'));

section('Message builder');
$d = $outbox->start($awa, 'new', $contact['id'], null, 'client@test.cm');
$outbox->save($awa, $d['id'], ['subject' => 'Test', 'html' => '<p>Bonjour</p><ul><li>un</li></ul>', 'bcc' => 'audit@lpc.cm']);
$m = MessageBuilder::build(Outbox::row($d['id']), $awa);
check('Bcc in the envelope only', in_array('audit@lpc.cm', $m->recipients(), true));
$m->from('contact@lpc.cm', 'LPC');
$raw = quoted_printable_decode($m->toString());
check('paragraph spacing written into the tags', str_contains($raw, '<p style="margin:0 0 12px;">Bonjour</p>') && str_contains($raw, '<ul style="margin:0 0 12px;padding-left:24px;">'));
check('no Bcc header', !str_contains($raw, 'Bcc:'));
check('plain text keeps the list', str_contains($raw, "- un"));
$dm = MessageBuilder::build(Outbox::row($d['id']), $awa, true);
$dm->from('contact@lpc.cm', 'LPC');
check('the draft copy has no signature', !str_contains(quoted_printable_decode($dm->toString()), 'La Petite Cour'));

section('Suggestions');
$list = Recipients::suggest($awa, 'mar');
check('people in mail Awa can read', in_array('marc@epicerie.test', array_column($list, 'email'), true));
$list = Recipients::suggest($awa, 'cont');
check('bureau’s mailboxes by name', ($list[0]['email'] ?? '') === 'contact@lpc.cm' && $list[0]['kind'] === 'mailbox', json_encode($list));
$list = Recipients::suggest($awa, 'Paul');
check('colleagues by name', in_array('paul.ndi@lpc.cm', array_column($list, 'email'), true), json_encode($list));
check('two letters at least', Recipients::suggest($awa, 'm') === []);
$list = Recipients::suggest($paul, 'marc');
check('Paul (Lecture on contact@) also sees its contacts', in_array('marc@epicerie.test', array_column($list, 'email'), true));
$outsider = mail_test_user('Sans Boite', 'driver');
check('without a mailbox, no contacts from mail', !in_array('marc@epicerie.test', array_column(Recipients::suggest($outsider, 'marc'), 'email'), true));

section('A devis link sent from Courrier (DocumentMail, api/v1/proposal_mail.php)');
$tpl = "Bonjour {contact},\n\nVeuillez trouver via le lien sécurisé ci-dessous notre proposition ({reference}).\n\nConsulter le Devis en ligne : {link}\n\n(Note: Vous pouvez télécharger le PDF depuis ce lien).\n\nCordialement,\n\n{sender}\nLa Petite Cour";
$split = \LPC\Mail\Compose\DocumentMail::splitTemplate($tpl, ['{contact}' => 'Luc TCHEUGOUE', '{reference}' => 'DEV-2611-00001', '{sender}' => 'Awa Mbarga']);
check('the Studio template splits around its {link} line', str_starts_with($split['before'], 'Bonjour Luc TCHEUGOUE,') && str_contains($split['before'], '(DEV-2611-00001)')
    && !str_contains($split['before'] . $split['after'], '{link}') && !str_contains($split['before'] . $split['after'], 'Consulter le Devis en ligne'));
check('…and loses its own sign-off, as the mailbox signature follows', str_ends_with($split['after'], 'Cordialement,') && !str_contains($split['after'], 'Awa Mbarga') && !str_contains($split['after'], 'La Petite Cour'));
$link = 'https://bureau.lpc.cm/quote.php?token=abc123';
$body = \LPC\Mail\Compose\DocumentMail::html("Bonjour <Luc> & co,\n\nVoici le devis.", 'Cordialement,', $link, 'Consulter la proposition', '#005A2B');
check('what the person typed is escaped', str_contains($body, 'Bonjour &lt;Luc&gt; &amp; co,') && !str_contains($body, '<Luc>'));
check('a branded button (table cell in the brand colour) and the link written out', str_contains($body, 'bgcolor="#005A2B"') && substr_count($body, 'href="' . $link . '"') === 2
    && str_contains($body, 'Si le bouton ne fonctionne pas'));
check('a link that is not http(s) is refused', throws(fn () => \LPC\Mail\Compose\DocumentMail::html('a', 'b', 'javascript:alert(1)', 'x')) instanceof InvalidArgumentException);

$before = count($server->sent);
$dm = $outbox->start($awa, 'new', $contact['id']);
$outbox->save($awa, $dm['id'], ['to' => 'Luc TCHEUGOUE <luc@smartlogistics.test>', 'subject' => 'Proposition Commerciale - La Petite Cour (DEV-2611-00001)', 'signature' => 'full']);
$pl = Outbox::row($dm['id'])['payload'];
$pl['html'] = $body;
Db::update('mail_outbox', ['payload' => Db::json($pl), 'updated_at' => Clock::sql()], $dm['id']);
$outbox->queue($awa, $dm['id']);
$row = $sender->send($dm['id'], 15);
check('sent from the connected mailbox', $row['status'] === 'sent' && count($server->sent) === $before + 1, json_encode([$row['status'], $row['last_error']]));
$wire = quoted_printable_decode($server->sent[$before]['raw']);
check('the button arrives intact (styles kept: it skipped HtmlCleaner)', str_contains($wire, 'bgcolor="#005A2B"') && str_contains($wire, 'Consulter la proposition') && str_contains($wire, 'color:#FFFFFF'));
check('the plain-text part carries the link too', substr_count($wire, $link) >= 3);
check('the mailbox signature follows the text', str_contains($wire, 'Mbarga') && strpos($wire, '>Cordialement,</p>') < strrpos($wire, 'Mbarga'));
check('a copy in Envoyés', count(array_filter($server->folder('contact@lpc.cm', 'INBOX.Sent'), fn ($m) => str_contains($m['raw'], $dm['message_id']))) === 1);

mail_test_done();
