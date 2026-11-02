<?php
/**
 * scripts/tests/mail/mail_db.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 1, against a real database (CI: a MariaDB 10.11 service, as on
 * the server) and the in-memory FakeMailServer: migration 300 applies (twice),
 * the first copy of a mailbox, later runs (new mail and mail.received, flags,
 * deletions), renumbered folders, threads, a refused password, the AI summary
 * kept until new mail, access levels (personal mailboxes private even from
 * admins), actions written to the server first, live reads, sending, and the
 * Boîtes mail operations (connect, shared mailboxes, people, password, rename,
 * remove). Ported from JBS HQ's Mail feature tests.
 *
 * Run:  MAIL_TEST_DB_PORT=3307 php scripts/tests/mail/mail_db.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\Events;
use LPC\Mail\MailAccess;
use LPC\Mail\MailActions;
use LPC\Mail\MailConnector;
use LPC\Mail\MailError;
use LPC\Mail\MailFetch;
use LPC\Mail\MailMembers;
use LPC\Mail\MailNav;
use LPC\Mail\MailSendException;
use LPC\Mail\MailTransport;
use LPC\Mail\Mime\Message;
use LPC\Mail\Store;
use LPC\Mail\Sync\MailSync;
use LPC\Mail\Tests\FakeMailServer;

$pdo = mail_test_db();

section('Migration 300');
$tables = $pdo->query("SHOW TABLES LIKE 'mail\\_%'")->fetchAll(PDO::FETCH_COLUMN);
check('11 mail tables', count($tables) === 11, implode(', ', $tables));
check('FULLTEXT index on messages (PR 5 search)', (bool) $pdo->query("SHOW INDEX FROM mail_messages WHERE Index_type = 'FULLTEXT'")->fetch());
check('mail.shared.manage granted to admin', (bool) Db::value("SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.name = 'admin' AND p.name = 'mail.shared.manage'"));

$admin = mail_test_user('Bruno Essomba', 'admin');
$awa = mail_test_user('Awa Mbarga', 'accountant');
$paul = mail_test_user('Paul Ndi', 'accountant');
$driver = mail_test_user('Jean Chauffeur', 'driver');
$gone = mail_test_user('Ancien Salarié', 'accountant', false);

$server = FakeMailServer::install();
$server->addMailbox('contact@lpc.cm', 'secret');
$received = [];
Events::reset(['mail.received' => [function (array $e) use (&$received) { $received[] = $e; }]]);

section('First copy: folders, messages, bodies, attachments, threads');
$old = (new DateTimeImmutable('-14 months'))->format('D, d M Y H:i:s O');
$recent = (new DateTimeImmutable('-2 days'))->format('D, d M Y H:i:s O');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(subject: 'Très vieux', date: $old, messageId: 'old@client.test'));
$u1 = $server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(
    from: 'Awa Client <awa@client.test>', subject: 'Commande de mars', text: "Bonjour,\nVoici la commande.", html: '<p>Bonjour,</p><p>Voici la commande.</p>',
    messageId: 'm1@client.test', date: $recent,
    attachments: [['name' => 'bon de commande.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 fake']],
), ['\\Seen']);
$server->deliver('contact@lpc.cm', 'INBOX.Sent', FakeMailServer::message(
    from: 'LPC <contact@lpc.cm>', to: 'awa@client.test', subject: 'Re: Commande de mars', text: 'Bien reçu.', messageId: 'm2@lpc.cm', inReplyTo: 'm1@client.test', date: $recent,
), ['\\Seen']);
$shared = mail_test_account('contact@lpc.cm', 'shared', $admin, 'secret', 'LPC Service client');
$r = MailSync::make()->account($shared['id']);
check('the run finished', $r['status'] === 'ok', json_encode($r));
$folders = Store::folders($shared['id']);
check('folders and roles from the server', array_column($folders, 'role') === ['inbox', 'drafts', 'sent', 'spam', 'trash'], json_encode(array_column($folders, 'role')));
check('only the last 12 months are copied', (int) Db::value('SELECT COUNT(*) FROM mail_messages WHERE account_id = ?', [$shared['id']]) === 2);
$m1 = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 'm1@client.test'"));
check('headers and flags', $m1['subject'] === 'Commande de mars' && $m1['from_email'] === 'awa@client.test' && $m1['is_seen'] && $m1['has_attachments']);
$body = Db::one('SELECT body_text, body_html, body_state FROM mail_messages WHERE id = ?', [$m1['id']]);
check('bodies copied (text and HTML)', $body['body_state'] === 'cached' && str_contains($body['body_text'], 'Voici la commande.') && str_contains($body['body_html'], '<p>Bonjour,</p>'));
$att = Store::attachments($m1['id']);
check('attachment details, the file stays on the server', count($att) === 1 && $att[0]['filename'] === 'bon de commande.pdf' && $att[0]['mime'] === 'application/pdf');
check('received_at is app time (Douala)', $m1['received_at'] === Clock::format(new DateTimeImmutable($recent)));
$m2 = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 'm2@lpc.cm'"));
check('the reply joins the conversation', $m2['thread_id'] === $m1['thread_id'] && $m2['is_outbound']);
$thread = Store::thread($m1['thread_id']);
check('thread counts', $thread['message_count'] === 2 && $thread['unread_count'] === 0 && $thread['has_attachments'] && $thread['subject'] === 'Commande de mars');
check('a first copy raises no mail.received', $received === []);
check('folder counts', Store::folderByRole($shared['id'], 'inbox')['total_count'] === 1);

section('Later runs: new mail, mail.received, flags, deletions');
$u3 = $server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Bob <bob@x.test>', subject: 'Facture impayée', messageId: 'm3@x.test'));
$server->setFlags('contact@lpc.cm', 'INBOX', $u1, ['\\Seen', '\\Flagged']);
$r = MailSync::make()->account($shared['id']);
check('new mail added', $r['added'] === 1 && $r['received'] === 1, json_encode($r));
$m3id = (int) Db::value("SELECT id FROM mail_messages WHERE message_id = 'm3@x.test'");
check('mail.received carries the new message and its thread', count($received) === 1 && $received[0]['account_id'] === $shared['id']
    && $received[0]['message_ids'] === [$m3id] && $received[0]['thread_ids'] === [Store::message($m3id)['thread_id']]);
check('a flag changed on the server is followed', Store::message($m1['id'])['is_flagged']);
check('unread count of the inbox', Store::folderByRole($shared['id'], 'inbox')['unread_count'] === 1);
$server->remove('contact@lpc.cm', 'INBOX', $u3);
MailSync::make()->account($shared['id']);
check('mail deleted on the server leaves bureau', !Db::value('SELECT 1 FROM mail_messages WHERE id = ?', [$m3id]));
check('and so does its empty thread', !Db::value("SELECT 1 FROM mail_threads WHERE subject = 'Facture impayée'"));

section('A renumbered folder is read again');
$server->renumber('contact@lpc.cm', 'INBOX');
$r = MailSync::make()->account($shared['id']);
check('UIDVALIDITY change: the copy is rebuilt', $r['status'] === 'ok' && (int) Db::value("SELECT COUNT(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE f.role = 'inbox' AND m.account_id = ?", [$shared['id']]) === 1);
$received = [];

section('Threads: by subject, then merged when a link turns up');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Chantal <chantal@y.test>', subject: 'Livraison Douala', messageId: 't1@y.test'));
MailSync::make()->account($shared['id']);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Chantal <chantal@y.test>', subject: 'RE: Livraison Douala', messageId: 't2@y.test'));
MailSync::make()->account($shared['id']);
$t1 = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 't1@y.test'"));
$t2 = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 't2@y.test'"));
check('a reply with the same subject and person joins by subject', $t1['thread_id'] === $t2['thread_id']);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Marc <marc@z.test>', subject: 'Livraison Douala', messageId: 'u1@z.test'));
MailSync::make()->account($shared['id']);
$u1row = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 'u1@z.test'"));
check('another original with the same subject is its own conversation', $u1row['thread_id'] !== $t1['thread_id']);
$merged = [];
Events::reset(['mail.threads_merged' => [function (array $e) use (&$merged) { $merged[] = $e; }]]);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Chantal <chantal@y.test>', subject: 'Re: Livraison', messageId: 'link@y.test', references: '<t1@y.test> <u1@z.test>'));
MailSync::make()->account($shared['id']);
check('a message citing both merges them into the oldest', Store::message($u1row['id'])['thread_id'] === $t1['thread_id'] && count($merged) === 1 && $merged[0]['into_id'] === $t1['thread_id']);
Events::reset([]);

section('The AI summary is kept until new mail');
Db::update('mail_threads', ['ai_summary' => ['gist' => 'x'], 'ai_summary_at' => Clock::sql()], $t1['thread_id']);
MailSync::make()->account($shared['id']);
check('kept while nothing new arrives', Store::thread($t1['thread_id'])['ai_summary'] === ['gist' => 'x']);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Chantal <chantal@y.test>', subject: 'Re: Livraison Douala', messageId: 't3@y.test', inReplyTo: 't2@y.test', date: (new DateTimeImmutable('+1 minute'))->format('D, d M Y H:i:s O')));
MailSync::make()->account($shared['id']);
check('cleared when a newer message arrives', Store::thread($t1['thread_id'])['ai_summary'] === null);

section('Access: everyone uses Mail; personal mailboxes are private; three levels');
check('every active login can use Mail (any role)', MailAccess::canUse($awa) && MailAccess::canUse($driver) && MailAccess::canUse($admin));
check('a login no longer active cannot', !MailAccess::canUse($gone) && !MailAccess::canUse(null));
check('only admins add shared mailboxes', MailAccess::canAddShared($admin) && !MailAccess::canAddShared($awa));
$server->addMailbox('awa.mbarga@lpc.cm', 'pw-awa');
$personal = mail_test_account('awa.mbarga@lpc.cm', 'personal', $awa, 'pw-awa', 'Awa Mbarga');
check('a personal mailbox: its person is owner', MailAccess::level($awa, $personal) === 'owner');
check('nobody else sees it, not even an admin', MailAccess::level($admin, $personal) === null && MailAccess::level($paul, $personal) === null
    && !in_array($personal['id'], MailAccess::accountIds($admin), true));
check('an admin manages every shared mailbox without being listed', MailAccess::level($admin, $shared) === 'manage');
check('a staff member without a level sees nothing', MailAccess::level($paul, $shared) === null && MailAccess::accountIds($paul) === []);
mail_test_member($shared, $paul, 'read');
check('Lecture: reads, cannot send or organise', MailAccess::can($paul, $shared, 'read') && !MailAccess::can($paul, $shared, 'send') && !MailAccess::can($paul, $shared, 'organise'));
Db::exec('UPDATE mail_account_members SET level = ? WHERE account_id = ? AND user_id = ?', ['send', $shared['id'], $paul]);
MailAccess::flush();
check('Lecture et envoi: sends and organises, cannot manage', MailAccess::can($paul, $shared, 'send') && MailAccess::can($paul, $shared, 'organise') && !MailAccess::can($paul, $shared, 'manage'));
$list = MailAccess::accounts($awa);
check('accounts(): personal first, with access_level', $list[0]['id'] === $personal['id'] && $list[0]['access_level'] === 'owner');
check('readers: members and admins, active only', MailAccess::readers($shared) === [$admin, $paul]);
check('managers: the listed manager and admins', MailAccess::managers($shared) === [$admin]);
check('readers of a personal mailbox: its person', MailAccess::readers($personal) === [$awa]);
check('authorize() throws 403', ($e = throws(fn () => MailAccess::authorize($driver, $shared, 'read'))) instanceof MailError && $e->status === 403);
$_SESSION = [];
check('the sidebar count: unread Inbox mail the person can open', MailNav::unread($admin) === (int) Db::value("SELECT SUM(unread_count) FROM mail_folders WHERE role = 'inbox' AND account_id = ?", [$shared['id']]));
check('nothing for someone without mailboxes', MailNav::unread($driver) === 0);

section('Actions: the server first, then bureau');
$unread = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 't1@y.test'"));
$inboxPath = 'INBOX';
MailActions::make()->opened($paul, $unread['id']);
$after = Store::message($unread['id']);
$onServer = array_values(array_filter($server->folder('contact@lpc.cm', $inboxPath), fn ($m) => str_contains($m['headers'], 't1@y.test')))[0];
check('opening marks read on the server and in bureau', $after['is_seen'] && in_array('\\Seen', $onServer['flags'], true));
check('and remembers who opened it first', $after['seen_by'] === $paul && $after['seen_at'] !== null);
MailActions::make()->opened($admin, $unread['id']);
check('the first opener stays', Store::message($unread['id'])['seen_by'] === $paul);
check('Lecture cannot flag', ($e = throws(function () use ($shared, $driver, $unread) {
    mail_test_member($shared, $driver, 'read');
    MailActions::make()->flag($driver, [$unread['id']]);
})) instanceof MailError && $e->status === 403);
MailActions::make()->moveToRole($paul, ['thread' => $unread['thread_id']], 'archive');
check('archive creates INBOX.Archive on the server', isset($server->mailboxes['contact@lpc.cm']['folders']['INBOX.Archive']));
$arch = Store::folderByRole($shared['id'], 'archive');
check('the messages moved, with their new UIDs, same thread', (int) Db::value('SELECT COUNT(*) FROM mail_messages WHERE folder_id = ? AND thread_id = ?', [$arch['id'], $unread['thread_id']]) >= 1
    && count($server->folder('contact@lpc.cm', 'INBOX.Archive')) >= 1);
$archived = Store::message((int) Db::value('SELECT id FROM mail_messages WHERE folder_id = ? LIMIT 1', [$arch['id']]));
check('delete for good refused outside Trash', ($e = throws(fn () => MailActions::make()->deleteForever($paul, [$archived['id']]))) instanceof MailError && $e->status === 422);
MailActions::make()->moveToRole($paul, [$archived['id']], 'trash');
$trashed = Store::message((int) Db::value("SELECT m.id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE f.role = 'trash' AND m.account_id = ? LIMIT 1", [$shared['id']]));
MailActions::make()->deleteForever($paul, [$trashed['id']]);
check('delete for good from Trash: gone on the server and in bureau', !Store::message($trashed['id']) && count($server->folder('contact@lpc.cm', 'INBOX.Trash')) === 0);
$server->deliver('awa.mbarga@lpc.cm', 'INBOX', FakeMailServer::message(to: 'awa.mbarga@lpc.cm', subject: 'Perso'));
MailSync::make()->account($personal['id']);
$own = (int) Db::value('SELECT id FROM mail_messages WHERE account_id = ? LIMIT 1', [$personal['id']]);
$sharedOne = (int) Db::value('SELECT id FROM mail_messages WHERE account_id = ? LIMIT 1', [$shared['id']]);
check('one mailbox at a time', $own > 0 && $sharedOne > 0 && ($e = throws(fn () => MailActions::make()->markRead($admin, [$sharedOne, $own]))) instanceof MailError && $e->status === 422);

section('Live reads: bodies, attachments, older mail, server search');
$m1now = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE message_id = 'm1@client.test'"));
$a = Store::attachments($m1now['id'])[0];
$file = MailFetch::make()->attachment($paul, $a['id']);
check('an attachment is read from the server, decoded', $file['content'] === '%PDF-1.4 fake' && $file['filename'] === 'bon de commande.pdf');
check('attachments need read access', ($e = throws(fn () => MailFetch::make()->attachment($gone, $a['id']))) instanceof MailError && $e->status === 403);
Db::update('mail_messages', ['body_state' => 'remote', 'body_text' => null, 'body_html' => null], $m1now['id']);
$b = MailFetch::make()->body($paul, $m1now['id']);
check('a remote body is read from the server when opened', str_contains($b['text'], 'Voici la commande.') && str_contains((string) $b['html'], '<p>Bonjour,</p>'));
$inbox = Store::folderByRole($shared['id'], 'inbox');
$older = MailFetch::make()->older($paul, $inbox['id']);
check('mail older than 12 months, live', count($older['items']) === 1 && $older['items'][0]['subject'] === 'Très vieux' && $older['next'] === null);
$live = MailFetch::make()->message($paul, $inbox['id'], $older['items'][0]['uid']);
check('a live message with its text', $live && str_contains($live['text'], 'Bonjour à vous.'));
$found = MailFetch::make()->searchServer($paul, $inbox['id'], 'vieux');
check('server search, all dates', count($found) === 1 && $found[0]['subject'] === 'Très vieux');

section('Sending: From is the mailbox, Bcc in the envelope, copy in Sent');
$msg = (new Message())->to('client@x.test')->bcc('archive@lpc.cm')->subject('Votre devis')->text('Voici le devis.');
$res = MailTransport::make()->send($paul, $shared, $msg);
$sent = end($server->sent);
check('sent through the mailbox, From = the mailbox and its name', $sent['account'] === 'contact@lpc.cm' && str_contains($sent['raw'], 'From: LPC Service client <contact@lpc.cm>'));
check('Bcc only in the envelope', $sent['recipients'] === ['client@x.test', 'archive@lpc.cm'] && !preg_match('/^Bcc:/mi', $sent['raw']));
check('a Message-ID on the mailbox domain', str_ends_with($res['message_id'], '@lpc.cm') && str_contains($sent['raw'], '<' . $res['message_id'] . '>'));
check('a copy in Sent, marked read', $res['sent_uid'] !== null && in_array('\\Seen', $server->folder('contact@lpc.cm', 'INBOX.Sent')[$res['sent_uid']]['flags'], true));
check('Lecture cannot send', ($e = throws(fn () => MailTransport::make()->send($driver, $shared, (new Message())->to('a@b.cm')->text('x')))) instanceof MailError && $e->status === 403);
$server->smtpFailure = 'temporary';
check('an SMTP refusal comes back as MailSendException', ($e = throws(fn () => MailTransport::make()->send($paul, $shared, (new Message())->to('a@b.cm')->text('x')))) instanceof MailSendException && $e->reason === 'temporary');
Db::insert('mail_outbox', ['id' => '00000000-0000-4000-8000-000000000001', 'account_id' => $shared['id'], 'user_id' => $paul, 'status' => 'sent',
    'payload' => [], 'message_id' => $res['message_id'], 'message_id_hash' => Store::hashId($res['message_id']), 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
$quick = MailSync::make()->roles($shared['id'], ['sent', 'drafts']);
check('a quick sync of Sent (and Drafts) brings the copy in', $quick['status'] === 'ok' && $quick['added'] === 1, json_encode($quick));
check('sync credits the sender from the outbox (sent_by)', (int) Db::value('SELECT sent_by FROM mail_messages WHERE message_id = ?', [$res['message_id']]) === $paul);
// Another process (a second connection) holding the mailbox's lock: neither kind of sync waits or runs twice
$other = new PDO('mysql:host=' . (getenv('MAIL_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('MAIL_TEST_DB_PORT') ?: '3306') . ';dbname=' . (getenv('MAIL_TEST_DB_NAME') ?: 'bureau_mail_test'),
    getenv('MAIL_TEST_DB_USER') ?: 'root', getenv('MAIL_TEST_DB_PASS') ?: '');
$other->query("SELECT GET_LOCK('" . MailSync::lockName($shared['id']) . "', 0)")->fetchColumn();
check('one sync per mailbox at a time (busy, not waiting)', MailSync::make()->roles($shared['id'], ['sent'])['status'] === 'busy' && MailSync::make()->account($shared['id'])['status'] === 'busy');
$other = null;

section('A refused password stops sync and tells the managers once');
$server->setPassword('contact@lpc.cm', 'changed');
$before = (int) Db::value('SELECT COUNT(*) FROM notifications');
$r = MailSync::make()->account($shared['id']);
check('the run reports the refusal', $r['status'] === 'error' && Store::account($shared['id'])['status'] === 'needs_reconnect');
$notes = Db::all('SELECT user_id, title, related_url FROM notifications ORDER BY id DESC LIMIT 5');
check('managers told (bell), with a link to Boîtes mail', (int) Db::value('SELECT COUNT(*) FROM notifications') === $before + 1
    && (int) $notes[0]['user_id'] === $admin && str_contains($notes[0]['related_url'], '/modules/mail/mailboxes.php?box=' . $shared['id']));
check('not tried again until someone enters the password', MailSync::make()->account($shared['id'])['status'] === 'skipped' && (int) Db::value('SELECT COUNT(*) FROM notifications') === $before + 1);
check('a new password must work before it is saved', ($e = throws(fn () => MailConnector::make()->updatePassword($admin, $shared['id'], 'wrong'))) instanceof MailError && $e->field === 'password');
MailConnector::make()->updatePassword($admin, $shared['id'], 'changed');
check('the right one restarts the mailbox', Store::account($shared['id'])['status'] === 'active' && MailSync::make()->account($shared['id'])['status'] === 'ok');
check('only managers update the password', ($e = throws(fn () => MailConnector::make()->updatePassword($paul, $shared['id'], 'changed'))) instanceof MailError && $e->status === 403);

section('Boîtes mail: connect, shared mailboxes, people, rename, remove');
$server->addMailbox('paul.ndi@lpc.cm', 'pw-paul');
$settings = \LPC\Mail\Autodiscover::for('paul.ndi@lpc.cm');
check('lpc.cm settings: mail.lpc.cm, 993 and 465, SSL/TLS', $settings === ['imap_host' => 'mail.lpc.cm', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => 'mail.lpc.cm', 'smtp_port' => 465, 'smtp_encryption' => 'ssl']);
check('a wrong password is refused on the password field', ($e = throws(fn () => MailConnector::make()->connectPersonal($paul, 'paul.ndi@lpc.cm', 'nope', $settings))) instanceof MailError && $e->field === 'password');
check('nothing saved after a refusal', !Store::accountByEmail('paul.ndi@lpc.cm'));
check('other ports are refused (no probing)', ($e = throws(fn () => MailConnector::make()->connectPersonal($paul, 'paul.ndi@lpc.cm', 'pw-paul', ['imap_port' => 22] + $settings))) instanceof MailError && $e->field === 'imap_host');
$sessionsBefore = $server->sessions;
check('a staff member cannot point bureau at an internal address', ($e = throws(fn () => MailConnector::make()->connectPersonal($paul, 'paul.ndi@lpc.cm', 'pw-paul', ['imap_host' => '127.0.0.1', 'imap_port' => 143] + $settings))) instanceof MailError && $e->field === 'imap_host');
check('nor at another host, nor without TLS', ($e = throws(fn () => MailConnector::make()->connectPersonal($paul, 'paul.ndi@lpc.cm', 'pw-paul', ['smtp_host' => 'smtp.example.org'] + $settings))) instanceof MailError && $e->field === 'smtp_host'
    && ($e2 = throws(fn () => MailConnector::make()->connectPersonal($paul, 'paul.ndi@lpc.cm', 'pw-paul', ['imap_encryption' => 'none'] + $settings))) instanceof MailError && $e2->field === 'imap_host');
check('refused before any connection, nothing saved', $server->sessions === $sessionsBefore && !Store::accountByEmail('paul.ndi@lpc.cm'));
$acc = MailConnector::make()->connectPersonal($paul, 'Paul.Ndi@lpc.cm', 'pw-paul', $settings);
check('connected: personal, active, address lower case, named after the person', $acc['kind'] === 'personal' && $acc['status'] === 'active' && $acc['email'] === 'paul.ndi@lpc.cm' && $acc['display_name'] === 'Paul Ndi');
$secret = (string) Db::value('SELECT secret FROM mail_accounts WHERE id = ?', [$acc['id']]);
check('the password is encrypted at rest', str_starts_with($secret, 'v1:') && !str_contains($secret, 'pw-paul') && Crypto::decrypt($secret) === 'pw-paul');
check('the account row never carries the secret', !array_key_exists('secret', $acc) && !array_key_exists('secret', Store::account($acc['id'])));
check('one personal mailbox per person', ($e = throws(fn () => MailConnector::make()->connectPersonal($paul, 'contact@lpc.cm', 'changed', $settings))) instanceof MailError && $e->field === 'email');
check('an address already shared cannot be connected as personal', ($e = throws(fn () => MailConnector::make()->connectPersonal($driver, 'contact@lpc.cm', 'changed', $settings))) instanceof MailError && str_contains($e->getMessage(), 'boîte partagée'));
check('staff cannot add a shared mailbox', ($e = throws(fn () => MailConnector::make()->addShared($awa, 'ventes@lpc.cm', 'x', $settings, 'Ventes'))) instanceof MailError && $e->status === 403);
$server->addMailbox('ventes@lpc.cm', 'pw-ventes');
$ventes = MailConnector::make()->addShared($admin, 'ventes@lpc.cm', 'pw-ventes', $settings, 'LPC Ventes');
check('an admin adds a shared mailbox and becomes its first manager', $ventes['kind'] === 'shared' && (string) Db::value('SELECT level FROM mail_account_members WHERE account_id = ? AND user_id = ?', [$ventes['id'], $admin]) === 'manage');
$before = (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$awa]);
MailMembers::add($admin, $ventes['id'], $awa, 'send');
check('adding a person tells them (bell, link to the mailbox)', (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$awa]) === $before + 1
    && str_contains((string) Db::value('SELECT related_url FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$awa]), '/modules/mail/index.php?box=' . $ventes['id']));
check('twice is refused', ($e = throws(fn () => MailMembers::add($admin, $ventes['id'], $awa, 'read'))) instanceof MailError && $e->field === 'invite_user');
check('a login no longer active cannot be added', throws(fn () => MailMembers::add($admin, $ventes['id'], $gone, 'read')) instanceof MailError);
$members = MailMembers::list($awa, $ventes['id']);
check('people and levels, managers first, French labels', count($members) === 2 && $members[0]['level'] === 'manage' && $members[1]['level_label'] === 'Lecture et envoi');
check('Lecture et envoi cannot manage people', ($e = throws(fn () => MailMembers::add($awa, $ventes['id'], $paul, 'read'))) instanceof MailError && $e->status === 403);
$awaMember = (int) Db::value('SELECT id FROM mail_account_members WHERE account_id = ? AND user_id = ?', [$ventes['id'], $awa]);
MailMembers::change($admin, $ventes['id'], $awaMember, 'manage');
check('change a level', (string) Db::value('SELECT level FROM mail_account_members WHERE id = ?', [$awaMember]) === 'manage');
$adminMember = (int) Db::value('SELECT id FROM mail_account_members WHERE account_id = ? AND user_id = ?', [$ventes['id'], $admin]);
MailMembers::remove($awa, $ventes['id'], $adminMember);
check('a manager removes another', !Db::value('SELECT 1 FROM mail_account_members WHERE id = ?', [$adminMember]));
check('the last manager cannot step down or leave', ($e = throws(fn () => MailMembers::change($awa, $ventes['id'], $awaMember, 'read'))) instanceof MailError && $e->field === 'members'
    && throws(fn () => MailMembers::remove($admin, $ventes['id'], $awaMember)) instanceof MailError);
check('invitable: active staff not yet on it', in_array($paul, array_column(MailMembers::invitable($awa, $ventes['id']), 'id'), true)
    && !in_array($awa, array_column(MailMembers::invitable($awa, $ventes['id']), 'id'), true) && !in_array($gone, array_column(MailMembers::invitable($awa, $ventes['id']), 'id'), true));
MailConnector::make()->rename($awa, $ventes['id'], 'LPC Ventes Douala');
check('rename (the From name)', Store::account($ventes['id'])['display_name'] === 'LPC Ventes Douala');
check('a blank name is refused', ($e = throws(fn () => MailConnector::make()->rename($awa, $ventes['id'], '  '))) instanceof MailError && $e->field === 'display_name');
MailSync::make()->account($ventes['id']);
$server->deliver('ventes@lpc.cm', 'INBOX', FakeMailServer::message(to: 'ventes@lpc.cm', subject: 'Prix'));
MailSync::make()->account($ventes['id']);
$serverCount = count($server->folder('ventes@lpc.cm', 'INBOX'));
MailConnector::make()->remove($awa, $ventes['id']);
check('remove: bureau\'s copy and people go (cascade)', !Store::account($ventes['id']) && !Db::value('SELECT 1 FROM mail_messages WHERE account_id = ?', [$ventes['id']])
    && !Db::value('SELECT 1 FROM mail_account_members WHERE account_id = ?', [$ventes['id']]));
check('the mail on the server is not touched', count($server->folder('ventes@lpc.cm', 'INBOX')) === $serverCount && $serverCount === 1);
check('every change was audited, never with a password', (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE table_name = 'mail_accounts'") >= 8
    && !Db::value("SELECT 1 FROM audit_logs WHERE new_value LIKE '%pw-%' OR new_value LIKE '%changed%'"));

section('Due list: a leaver\'s personal mailbox is not synced');
$server->addMailbox('ancien@lpc.cm', 'pw');
$leaver = mail_test_account('ancien@lpc.cm', 'personal', $gone, 'pw');
check('due() skips it, keeps shared and active personal ones', !in_array($leaver['id'], array_column(MailSync::due(), 'id'), true) && in_array($shared['id'], array_column(MailSync::due(), 'id'), true));

mail_test_done();
