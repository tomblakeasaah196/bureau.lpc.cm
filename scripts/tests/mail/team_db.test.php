<?php
/**
 * scripts/tests/mail/team_db.test.php
 * -----------------------------------------------------------------------------
 * Courrier PR 4 against a real database (CI: MariaDB 10.11) and the in-memory
 * FakeMailServer: new-mail alerts through the real mail.received listener (who
 * gets a bell, who never does, a Traitée conversation opening again, the sync
 * never failing because of them), assignment, Ouverte / Traitée, internal
 * notes, presence (viewing / replying), the activity timeline in French, the
 * team bar's state per level, the merge listener and the mount. Personal
 * mailboxes have no teamwork. Ported from JBS HQ's Team tests.
 *
 * Run:  MAIL_TEST_DB_PORT=3307 MAIL_TEST_DB_NAME=bureau_mail_test_pr4 php scripts/tests/mail/team_db.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\Events;
use LPC\Mail\MailError;
use LPC\Mail\Mount;
use LPC\Mail\Store;
use LPC\Mail\Sync\MailSync;
use LPC\Mail\Sync\Threader;
use LPC\Mail\Team\MailPresence;
use LPC\Mail\Team\MailTeam;
use LPC\Mail\Team\NewMailNotices;
use LPC\Mail\Tests\FakeMailServer;

$pdo = mail_test_db();

$admin = mail_test_user('Bruno Essomba', 'admin');
$awa = mail_test_user('Awa Mbarga');          // Lecture et envoi on contact@, and her own mailbox
$paul = mail_test_user('Paul Ndi');           // Lecture on contact@
$chantal = mail_test_user('Chantal Eyenga');  // Gestionnaire on contact@
$jean = mail_test_user('Jean Chauffeur', 'driver');           // no access to contact@
$gone = mail_test_user('Ancien Salarié', 'accountant', false); // listed, but no longer active

$server = FakeMailServer::install();
$server->addMailbox('contact@lpc.cm', 'secret');
$server->addMailbox('awa.mbarga@lpc.cm', 'secret');
$contact = mail_test_account('contact@lpc.cm', 'shared', $chantal, 'secret', 'LPC Service client');
mail_test_member($contact, $awa, 'send');
mail_test_member($contact, $paul, 'read');
mail_test_member($contact, $gone, 'send');
$own = mail_test_account('awa.mbarga@lpc.cm', 'personal', $awa, 'secret', 'Awa Mbarga');

Events::reset(null);   // the real listeners in includes/mail/Listeners (team_*.php among them)

$date = fn (string $ago) => (new DateTimeImmutable($ago))->format('D, d M Y H:i:s O');
$alerts = fn (?int $userId = null) => array_map(fn ($r) => $r + ['user_id' => (int) $r['user_id']], Db::all(
    "SELECT user_id, title, message, type, related_url FROM notifications WHERE title LIKE 'Nouveau message%'" . ($userId ? ' AND user_id = ' . (int) $userId : '') . ' ORDER BY id',
));
$recipients = function (string $since) {
    $ids = array_map('intval', Db::column("SELECT DISTINCT user_id FROM notifications WHERE title LIKE 'Nouveau message%' AND id > ? ORDER BY user_id", [$since]));
    sort($ids);

    return $ids;
};
$lastId = fn () => (string) (Db::value('SELECT COALESCE(MAX(id), 0) FROM notifications') ?? 0);
$sync = fn (array $account) => MailSync::make()->account($account['id']);
$threadOf = fn (string $messageId) => (int) Db::value('SELECT thread_id FROM mail_messages WHERE message_id_hash = ? LIMIT 1', [Store::hashId($messageId)]);

// ============================================================================
section('First copy: no alerts');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Brasseries <commandes@sabc.test>', subject: 'Commande n° 4471', messageId: 'c1@sabc.test', date: $date('-3 hours')));
$server->deliver('awa.mbarga@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Paul Ndi <paul.ndi@lpc.cm>', to: 'awa.mbarga@lpc.cm', subject: 'Réunion', messageId: 'r1@lpc.test', date: $date('-2 hours')));
$r1 = $sync($contact);
$sync($own);
check('first copy synced', $r1['status'] === 'ok' && $r1['added'] === 1, json_encode($r1));
check('no alert for a mailbox’s first copy', $alerts() === []);

// ============================================================================
section('New mail: one bell per message, to everyone who can read the mailbox');
$before = $lastId();
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Marc Etoundi <marc@epicerie.test>', subject: 'Facture de septembre', text: 'Merci de renvoyer la facture.', messageId: 'm1@epicerie.test', date: $date('-10 minutes')));
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'marc@epicerie.test', subject: '', text: "Bonjour,\nun deux trois quatre cinq six sept huit neuf dix onze douze treize quatorze", messageId: 'm2@epicerie.test', date: $date('-5 minutes')));
$r = $sync($contact);
check('sync reported 2 received', $r['received'] === 2, json_encode($r));
$rows = Db::all("SELECT user_id, title, message, type, related_url FROM notifications WHERE id > ? ORDER BY id", [$before]);
check('2 messages × 4 readers = 8 bells', count($rows) === 8, (string) count($rows));
check('to the members and the admin, not Jean (no access) nor an inactive member', $recipients($before) === [$admin, $awa, $paul, $chantal], json_encode($recipients($before)));
$m1Thread = $threadOf('m1@epicerie.test');
$first = $rows[0];
check('title: Nouveau message : <sender name>', $first['title'] === 'Nouveau message : Marc Etoundi', $first['title']);
check('message: the subject · the shared mailbox', $first['message'] === 'Facture de septembre · contact@lpc.cm', $first['message']);
check('link to the conversation (MailNotices::inboxUrl)', $first['related_url'] === "/modules/mail/index.php?box={$contact['id']}&thread={$m1Thread}", $first['related_url']);
check('type info', $first['type'] === 'info');
check('oldest message first: its 4 bells, then the next message’s', count(array_unique(array_column(array_slice($rows, 0, 4), 'message'))) === 1 && $rows[4]['message'] !== $rows[0]['message']);
$noSubject = array_values(array_filter($rows, fn ($r) => str_contains($r['message'], 'Bonjour')))[0] ?? null;
check('no name: the address', $noSubject && $noSubject['title'] === 'Nouveau message : marc@epicerie.test', $noSubject['title'] ?? '');
check('no subject: its first 12 words, then …', $noSubject && $noSubject['message'] === 'Bonjour, un deux trois quatre cinq six sept huit neuf dix onze… · contact@lpc.cm', $noSubject['message'] ?? '');

section('Personal mailbox: only its person, without the mailbox name');
$before = $lastId();
$server->deliver('awa.mbarga@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Client <client@client.test>', to: 'awa.mbarga@lpc.cm', subject: 'Pour vous', messageId: 'p1@client.test'));
$sync($own);
check('only Awa (not even the admin)', $recipients($before) === [$awa], json_encode($recipients($before)));
check('message without " · <boîte>"', ($alerts($awa)[count($alerts($awa)) - 1]['message'] ?? '') === 'Pour vous');

section('Nobody is alerted about mail they sent themselves');
$before = $lastId();
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Awa Mbarga <awa.mbarga@lpc.cm>', subject: 'Transfert du devis', messageId: 'own1@lpc.test'));
$sync($contact);
check('sent from a reader’s own address (phone, webmail): not to them', $recipients($before) === [$admin, $paul, $chantal], json_encode($recipients($before)));

$before = $lastId();
Db::insert('mail_outbox', [
    'id' => '00000000-0000-4000-8000-000000000001', 'account_id' => $own['id'], 'user_id' => $paul, 'kind' => 'new', 'status' => 'sent',
    'payload' => '{}', 'message_id' => 'w1@lpc.test', 'message_id_hash' => Store::hashId('w1@lpc.test'), 'idempotency_key' => 'team-test-1',
    'created_at' => Clock::sql(), 'updated_at' => Clock::sql(),
]);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Service Achats <achats@lpc.cm>', subject: 'Écrit dans bureau', messageId: 'w1@lpc.test'));
$sync($contact);
check('written in bureau (outbox row with the same Message-ID): not to its writer', $recipients($before) === [$admin, $awa, $chantal], json_encode($recipients($before)));

$before = $lastId();
$sentById = $server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Quelqu’un <q@client.test>', subject: 'Avec sent_by', messageId: 'sb1@client.test'));
$sync($contact);
$sbId = (int) Db::value('SELECT id FROM mail_messages WHERE message_id_hash = ?', [Store::hashId('sb1@client.test')]);
Db::exec('DELETE FROM notifications WHERE id > ?', [$before]);
Db::update('mail_messages', ['sent_by' => $chantal], $sbId);
NewMailNotices::received($contact['id'], [$sbId]);
check('sent_by is skipped', $recipients($before) === [$admin, $awa, $paul], json_encode($recipients($before)));

$before = $lastId();
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'LPC Service client <contact@lpc.cm>', to: 'contact@lpc.cm', subject: 'Copie à soi', messageId: 'self1@lpc.test'));
$sync($contact);
$selfId = (int) Db::value('SELECT id FROM mail_messages WHERE message_id_hash = ?', [Store::hashId('self1@lpc.test')]);
check('mail from the mailbox itself raises nothing', $recipients($before) === []);
check('…and received() skips outbound mail even when asked', NewMailNotices::received($contact['id'], [$selfId]) === 0);
check('a message of another mailbox is ignored', NewMailNotices::received($own['id'], [$sbId]) === 0);

// ============================================================================
section('A Traitée conversation opens again when new mail arrives');
MailTeam::setStatus($awa, $m1Thread, 'done');
check('marked Traitée', Store::thread($m1Thread)['status'] === 'done');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Marc Etoundi <marc@epicerie.test>', subject: 'RE: Facture de septembre', text: 'La voici.', messageId: 'm3@epicerie.test', inReplyTo: 'm1@epicerie.test', references: '<m1@epicerie.test>'));
$sync($contact);
$t = Store::thread($m1Thread);
check('the reply joined the conversation', $threadOf('m3@epicerie.test') === $m1Thread);
check('back to Ouverte, done_by and done_at cleared', $t['status'] === 'open' && $t['done_by'] === null && $t['done_at'] === null, json_encode([$t['status'], $t['done_by']]));
$reopen = Db::one("SELECT * FROM mail_thread_activity WHERE thread_id = ? AND type = 'reopened' ORDER BY id DESC LIMIT 1", [$m1Thread]);
$data = Db::decode($reopen['data'] ?? null) ?? [];
check('activity: reopened by the system, reason reply, with the message', $reopen && $reopen['user_id'] === null && ($data['reason'] ?? '') === 'reply' && ($data['message_id'] ?? 0) > 0);
check('an Ouverte conversation is left as it is', MailTeam::reopenOnReply($m1Thread, 1) === false);

// ============================================================================
section('New-mail alerts never fail the sync');
$pdo->exec('RENAME TABLE notifications TO notifications_away');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Marc <marc@epicerie.test>', subject: 'Pendant une panne', messageId: 'down1@epicerie.test'));
$r = $sync($contact);
$pdo->exec('RENAME TABLE notifications_away TO notifications');
check('bell table missing: sync still ok and the mail is stored', $r['status'] === 'ok' && $r['received'] === 1 && $threadOf('down1@epicerie.test') > 0, json_encode($r));

$pdo->exec('RENAME TABLE mail_outbox TO mail_outbox_away');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Marc <marc@epicerie.test>', subject: 'Pendant une autre panne', messageId: 'down2@epicerie.test'));
$e = throws(fn () => $sync($contact));
$r = $e ? null : MailSync::make()->account($contact['id']);
$pdo->exec('RENAME TABLE mail_outbox_away TO mail_outbox');
check('the listener itself failing: no exception out of sync, mail stored', $e === null && $threadOf('down2@epicerie.test') > 0, $e ? $e->getMessage() : '');
$listeners = require dirname(__DIR__, 3) . '/includes/mail/Listeners/team_new_mail.php';
check('listener with nonsense input does not throw', throws(fn () => $listeners['mail.received'](['account_id' => 'x', 'message_ids' => [0, 'y', 999999]])) === null);
check('listener with an unknown mailbox does not throw', throws(fn () => $listeners['mail.received'](['account_id' => 999999, 'message_ids' => [1]])) === null);
check('listener with nothing at all does not throw', throws(fn () => $listeners['mail.received']([])) === null);

// ============================================================================
section('Assignment');
$thread = $m1Thread;
$names = array_column(MailTeam::assignees($contact), 'name');
check('assignees: those who can reply (admin, send, manage), by name', $names === ['Awa Mbarga', 'Bruno Essomba', 'Chantal Eyenga'], json_encode($names));
check('assignees: none on a personal mailbox', MailTeam::assignees($own) === []);

$e = throws(fn () => MailTeam::assign($paul, $thread, $awa));
check('Lecture cannot assign (403)', $e instanceof MailError && $e->status === 403 && str_contains($e->getMessage(), 'Lecture et envoi'), $e ? $e->getMessage() : '');
$e = throws(fn () => MailTeam::assign($awa, $thread, $paul));
check('only people who can reply can be assigned (422 on assigned_to)', $e instanceof MailError && $e->status === 422 && $e->field === 'assigned_to');
$e = throws(fn () => MailTeam::assign($awa, $thread, $jean));
check('someone without access cannot be assigned', $e instanceof MailError && $e->status === 422);
$e = throws(fn () => MailTeam::assign($awa, $thread, $gone));
check('an inactive login cannot be assigned', $e instanceof MailError && $e->status === 422);
$e = throws(fn () => MailTeam::assign($jean, $thread, $jean));
check('no access at all: 404 (the conversation is not revealed)', $e instanceof MailError && $e->status === 404);

$before = $lastId();
check('Awa assigns Chantal', MailTeam::assign($awa, $thread, $chantal) === true);
$t = Store::thread($thread);
check('assigned_to / assigned_by / assigned_at', $t['assigned_to'] === $chantal && $t['assigned_by'] === $awa && $t['assigned_at'] !== null);
$bell = Db::one("SELECT * FROM notifications WHERE id > ? AND title = 'Conversation qui vous est assignée'", [$before]);
check('a bell to the assignee', $bell && (int) $bell['user_id'] === $chantal, json_encode($bell));
check('…saying who, what and where, linked to the conversation',
    $bell && $bell['message'] === 'Awa Mbarga vous a assigné la conversation « Facture de septembre » de contact@lpc.cm.' && $bell['related_url'] === "/modules/mail/index.php?box={$contact['id']}&thread={$thread}",
    $bell['message'] ?? '');
check('the same assignment again changes nothing', MailTeam::assign($awa, $thread, $chantal) === false);
check('…and logs nothing more', (int) Db::value("SELECT COUNT(*) FROM mail_thread_activity WHERE thread_id = ? AND type = 'assigned'", [$thread]) === 1);

$before = $lastId();
MailTeam::assign($admin, $thread, $admin);
check('an admin can take it (not listed, manages every shared mailbox)', Store::thread($thread)['assigned_to'] === $admin);
check('no bell when you take it yourself', !Db::value("SELECT 1 FROM notifications WHERE id > ? AND title = 'Conversation qui vous est assignée'", [$before]));
MailTeam::assign($awa, $thread, null);
check('unassigned', Store::thread($thread)['assigned_to'] === null && Store::thread($thread)['assigned_by'] === null);

$ownThread = $threadOf('r1@lpc.test');
$e = throws(fn () => MailTeam::assign($awa, $ownThread, $awa));
check('no teamwork on a personal mailbox (404), even for its person', $e instanceof MailError && $e->status === 404 && str_contains($e->getMessage(), 'boîtes partagées'), $e ? $e->getMessage() : '');
$e = throws(fn () => MailTeam::state($admin, $ownThread));
check('an admin cannot reach a personal conversation either', $e instanceof MailError && $e->status === 404);

// ============================================================================
section('Ouverte / Traitée');
$e = throws(fn () => MailTeam::setStatus($paul, $thread, 'done'));
check('Lecture cannot change it (403)', $e instanceof MailError && $e->status === 403);
$e = throws(fn () => MailTeam::setStatus($awa, $thread, 'closed'));
check('only open or done (422)', $e instanceof MailError && $e->status === 422);
check('Awa marks it Traitée', MailTeam::setStatus($awa, $thread, 'done') === true);
$t = Store::thread($thread);
check('done_by and done_at', $t['status'] === 'done' && $t['done_by'] === $awa && $t['done_at'] !== null);
check('again: nothing changes', MailTeam::setStatus($awa, $thread, 'done') === false);
check('Rouvrir', MailTeam::setStatus($chantal, $thread, 'open') === true && Store::thread($thread)['status'] === 'open');

// ============================================================================
section('Internal notes');
$n1 = MailTeam::addNote($paul, $thread, "  Client rappelé.\r\nBon de commande lundi. <script>x</script>  ");
check('Lecture adds a note', $n1['id'] > 0 && $n1['mine'] === true && $n1['can_delete'] === true);
check('kept as plain text, trimmed, line breaks normalised (escaping is the page’s job)', $n1['body'] === "Client rappelé.\nBon de commande lundi. <script>x</script>", json_encode($n1['body']));
$e = throws(fn () => MailTeam::addNote($paul, $thread, "   \n "));
check('empty note: 422 on body', $e instanceof MailError && $e->status === 422 && $e->field === 'body');
$e = throws(fn () => MailTeam::addNote($paul, $thread, str_repeat('é', 5001)));
check('over 5 000 characters: 422 on body', $e instanceof MailError && $e->field === 'body' && str_contains($e->getMessage(), '5 000'), $e ? $e->getMessage() : '');
check('5 000 characters is fine', MailTeam::addNote($paul, $thread, str_repeat('é', 5000))['id'] > 0);
$n2 = MailTeam::addNote($awa, $thread, 'Je m’en occupe.');
$e = throws(fn () => MailTeam::addNote($jean, $thread, 'Bonjour'));
check('no access: no note', $e instanceof MailError && $e->status === 404);
$e = throws(fn () => MailTeam::addNote($awa, $ownThread, 'Note'));
check('no notes on a personal mailbox', $e instanceof MailError && $e->status === 404);

$notes = MailTeam::notes($paul, $thread);
check('notes oldest first, with author', count($notes) === 3 && $notes[0]['author'] === 'Paul Ndi' && $notes[2]['author'] === 'Awa Mbarga');
check('Paul may delete his own, not Awa’s', $notes[0]['can_delete'] && !$notes[2]['can_delete']);
check('a Gestionnaire may delete any note', MailTeam::notes($chantal, $thread)[2]['can_delete'] === true);
$e = throws(fn () => MailTeam::deleteNote($paul, $n2['id']));
check('Lecture cannot delete someone else’s note (403)', $e instanceof MailError && $e->status === 403);
MailTeam::deleteNote($paul, $n1['id']);
check('own note deleted: hidden, kept with deleted_at', count(MailTeam::notes($awa, $thread)) === 2 && Db::value('SELECT deleted_at FROM mail_thread_notes WHERE id = ?', [$n1['id']]) !== null);
$e = throws(fn () => MailTeam::deleteNote($paul, $n1['id']));
check('deleting twice: 404', $e instanceof MailError && $e->status === 404);
MailTeam::deleteNote($chantal, $n2['id']);
check('the Gestionnaire deleted Awa’s note', count(MailTeam::notes($awa, $thread)) === 1);
$e = throws(fn () => MailTeam::deleteNote($jean, $notes[1]['id']));
check('no access: cannot delete', $e instanceof MailError && $e->status === 404);

// ============================================================================
section('Presence: viewing and replying');
Db::exec('DELETE FROM mail_presence');
check('Awa views it', MailPresence::touch($awa, $thread) === true);
$row = Db::one('SELECT * FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$thread, $awa]);
$secs = Clock::ageSeconds($row['expires_at']);
check('the mark lasts about 60 s', $row['state'] === 'viewing' && $secs !== null && $secs <= -55 && $secs >= -65, (string) $secs);
$others = MailPresence::others($paul, $thread);
check('Paul sees Awa viewing', count($others) === 1 && $others[0]['user_id'] === $awa && $others[0]['state'] === 'viewing' && $others[0]['name'] === 'Awa Mbarga' && $others[0]['initials'] === 'AM');
check('Awa does not see herself', MailPresence::others($awa, $thread) === []);
MailPresence::touch($paul, $thread);
MailPresence::touch($chantal, $thread, 'replying');
$others = MailPresence::others($admin, $thread);
check('those replying come first, then by name', array_column($others, 'user_id') === [$chantal, $awa, $paul], json_encode(array_column($others, 'user_id')));
MailPresence::touch($chantal, $thread, 'viewing');
check('a renewal as viewing keeps an unexpired replying', Db::value('SELECT state FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$thread, $chantal]) === 'replying');
MailPresence::stopReplying($chantal, $thread);
check('stopped: back to viewing', Db::value('SELECT state FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$thread, $chantal]) === 'viewing');
MailPresence::touch($chantal, $thread, 'replying');
Db::exec('UPDATE mail_presence SET expires_at = ? WHERE user_id = ?', [Clock::sql(-5), $chantal]);
MailPresence::touch($chantal, $thread, 'viewing');
check('an expired replying is not kept', Db::value('SELECT state FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$thread, $chantal]) === 'viewing');
Db::exec('UPDATE mail_presence SET expires_at = ? WHERE user_id = ?', [Clock::sql(-1), $paul]);
check('expired marks are hidden at once', !in_array($paul, array_column(MailPresence::others($admin, $thread), 'user_id'), true));
MailPresence::leave($awa, $thread);
check('leave removes the mark', !Db::value('SELECT 1 FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$thread, $awa]));
check('no mark on a personal mailbox', MailPresence::touch($awa, $ownThread) === false && !Db::value('SELECT 1 FROM mail_presence WHERE thread_id = ?', [$ownThread]));
check('no mark without access', MailPresence::touch($jean, $thread) === false);
check('no unknown state', MailPresence::touch($awa, $thread, 'typing') === false);
Db::exec('INSERT INTO mail_presence (thread_id, user_id, state, expires_at) VALUES (?, ?, ?, ?)', [$thread, $jean, 'viewing', Clock::sql(60)]);
check('someone who cannot read the mailbox is never shown', !in_array($jean, array_column(MailPresence::others($admin, $thread), 'user_id'), true));
check('without access you see nobody', MailPresence::others($jean, $thread) === []);
MailTeam::configure(['presence_seconds' => 15]);
MailPresence::touch($awa, $thread);
$secs = Clock::ageSeconds(Db::value('SELECT expires_at FROM mail_presence WHERE thread_id = ? AND user_id = ?', [$thread, $awa]));
check('presence_seconds comes from team_config.php', $secs <= -10 && $secs >= -20, (string) $secs);
MailTeam::configure(null);

// ============================================================================
section('Activity timeline, in French, "Vous" for the reader');
$activity = MailTeam::activity($awa, $thread);
$texts = array_column($activity, 'text');
check('newest first', $activity[0]['type'] === 'note_deleted', $activity[0]['type']);
check('note deleted by the Gestionnaire', $texts[0] === 'Chantal Eyenga a supprimé une note');
check('own note: "Vous avez ajouté une note"', in_array('Vous avez ajouté une note', $texts, true));
check('reopened by Chantal', in_array('Chantal Eyenga l’a rouverte', $texts, true));
check('marked done by me', in_array('Vous l’avez marquée traitée', $texts, true));
check('unassigned by me', in_array('Vous avez retiré l’assignation', $texts, true));
check('the admin took it', in_array('Bruno Essomba a pris cette conversation', $texts, true));
check('I assigned Chantal', in_array('Vous l’avez assignée à Chantal Eyenga', $texts, true));
check('reopened by new mail', in_array('Rouverte : un nouveau message est arrivé', $texts, true));
$chantalTexts = array_column(MailTeam::activity($chantal, $thread), 'text');
check('Chantal reads "Awa Mbarga vous l’a assignée"', in_array('Awa Mbarga vous l’a assignée', $chantalTexts, true));
check('Paul reads "Awa Mbarga l’a assignée à Chantal Eyenga"', in_array('Awa Mbarga l’a assignée à Chantal Eyenga', array_column(MailTeam::activity($paul, $thread), 'text'), true));
MailTeam::log($thread, $awa, 'sent', ['outbox_id' => 'x']);
check('PR 3’s "sent" reads "Vous avez répondu"', MailTeam::activity($awa, $thread)[0]['text'] === 'Vous avez répondu');
check('…and "Awa Mbarga a répondu" for others', MailTeam::activity($paul, $thread)[0]['text'] === 'Awa Mbarga a répondu');
check('times carry a label ("HH:MM" today) and an ISO date', preg_match('/^\d{2}:\d{2}$/', $activity[0]['at_label']) === 1 && str_contains((string) $activity[0]['at'], 'T'));
check('limit', count(MailTeam::activity($awa, $thread, 3)) === 3);
$e = throws(fn () => MailTeam::activity($jean, $thread));
check('no access: no timeline', $e instanceof MailError && $e->status === 404);

section('Time labels');
Clock::freeze(new DateTimeImmutable('2026-11-02 15:00:00', new DateTimeZone('Africa/Douala')));
check('today', MailTeam::when('2026-11-02 10:42:00') === '10:42');
check('yesterday', MailTeam::when('2026-11-01 09:05:00') === 'hier, 09:05');
check('this year', MailTeam::when('2026-10-03 08:00:00') === '3 oct., 08:00');
check('another year', MailTeam::when('2025-12-24 18:30:00') === '24 déc. 2025, 18:30');
check('no date', MailTeam::when(null) === '');
Clock::freeze(null);

// ============================================================================
section('The team bar’s state per level');
MailTeam::assign($awa, $thread, $awa);
MailPresence::touch($chantal, $thread, 'replying');
$s = MailTeam::state($paul, $thread);
check('Lecture: sees status, assignee, notes and presence', $s['status'] === 'open' && $s['assignee']['id'] === $awa && count($s['notes']) === 1 && $s['presence'] !== []);
check('Lecture: cannot send, no list of people to assign', $s['can'] === ['send' => false, 'manage' => false] && $s['assignees'] === []);
check('activity only when asked', $s['activity'] === null && is_array(MailTeam::state($paul, $thread, true)['activity']));
check('poll_seconds for the page', $s['poll_seconds'] === 20);
$s = MailTeam::state($awa, $thread);
check('Lecture et envoi: can send, sees the assignees with "me"', $s['can']['send'] === true && $s['can']['manage'] === false && count($s['assignees']) === 3 && array_column(array_filter($s['assignees'], fn ($p) => $p['me']), 'id') === [$awa]);
check('assignee marked as me and able to reply', $s['assignee']['me'] === true && $s['assignee']['can_reply'] === true);
check('Gestionnaire: manage', MailTeam::state($chantal, $thread)['can'] === ['send' => true, 'manage' => true]);
MailTeam::setStatus($awa, $thread, 'done');
$s = MailTeam::state($paul, $thread);
check('Traitée: who and when', $s['status'] === 'done' && $s['done_by']['name'] === 'Awa Mbarga' && $s['done_at_label'] !== '');
Db::exec("UPDATE mail_account_members SET level = 'read' WHERE account_id = ? AND user_id = ?", [$contact['id'], $awa]);
\LPC\Mail\MailAccess::flush();
$s = MailTeam::state($chantal, $thread);
check('an assignee who lost Lecture et envoi is shown as unable to reply', $s['assignee']['id'] === $awa && $s['assignee']['can_reply'] === false);
Db::exec("UPDATE mail_account_members SET level = 'send' WHERE account_id = ? AND user_id = ?", [$contact['id'], $awa]);
\LPC\Mail\MailAccess::flush();
check('Jean (no access): 404', throws(fn () => MailTeam::state($jean, $thread)) instanceof MailError);
check('unknown conversation: 404', (throws(fn () => MailTeam::state($admin, 999999))?->status ?? 0) === 404);

// ============================================================================
section('Merged conversations: a line in the timeline');
$a = $threadOf('c1@sabc.test');
$b = $threadOf('down1@epicerie.test');
Threader::merge([$b], $a);
$merged = Db::one("SELECT * FROM mail_thread_activity WHERE thread_id = ? AND type = 'merged'", [$a]);
check('merged logged on the conversation that stays', $merged && (Db::decode($merged['data'])['from'] ?? null) === [$b]);
check('…in French', in_array('Réunie avec une autre conversation du même échange', array_column(MailTeam::activity($admin, $a), 'text'), true));
$mergedListener = require dirname(__DIR__, 3) . '/includes/mail/Listeners/team_merged.php';
check('merge listener with nonsense does not throw', throws(fn () => $mergedListener['mail.threads_merged'](['into_id' => 'x'])) === null);
check('…nor for a conversation that is gone', throws(fn () => $mergedListener['mail.threads_merged'](['into_id' => 999999, 'from_ids' => [1]])) === null
    && !Db::value('SELECT 1 FROM mail_thread_activity WHERE thread_id = 999999'));

// ============================================================================
section('The mount: one hidden container per page');
if (!function_exists('lpc_asset')) {
    function lpc_asset(string $path): string
    {
        return $path;
    }
}
ob_start();
Mount::render('thread_team', ['user_id' => $paul, 'account_id' => $contact['id'], 'folder' => 'inbox', 'thread_id' => $thread, 'shared' => true]);
$html = ob_get_clean();
check('prints the container, hidden, with the conversation and its kind', str_contains($html, 'data-mail-team') && str_contains($html, ' hidden ') && str_contains($html, 'data-team-thread="' . $thread . '"') && str_contains($html, 'data-team-shared="1"'));
check('loads its script and style', str_contains($html, '/assets/js/modules/mail-team.js') && str_contains($html, '/assets/css/lpc-mail-team.css'));
ob_start();
Mount::render('thread_team', ['user_id' => $paul, 'account_id' => $contact['id'], 'thread_id' => $thread, 'shared' => true]);
check('a second render prints nothing', ob_get_clean() === '');

mail_test_done();
