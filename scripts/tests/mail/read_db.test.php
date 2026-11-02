<?php
/**
 * scripts/tests/mail/read_db.test.php
 * -----------------------------------------------------------------------------
 * Courrier, PR 2 (reading), against a real database (CI: MariaDB 10.11) and the
 * in-memory FakeMailServer: migration 310 (image trust), where a link lands
 * (?box= / ?thread=, personal mailboxes private even from admins), the
 * conversation list (badges, filters, pages), search (bureau's copy: FULLTEXT,
 * then word by word; de:; Indésirables left out), the reader (one row per
 * message, opening marks read for everyone with "Ouvert par", HTML only in a
 * sandboxed frame, pictures held back, cid: pictures, attachments, buttons by
 * level), "Toujours pour cet expéditeur", the toolbar actions with Annuler,
 * emptying Indésirables, older mail read live, and the counts the page polls.
 *
 * Run:  MAIL_TEST_DB_PORT=3307 MAIL_TEST_DB_NAME=bureau_mail_test_pr2 php scripts/tests/mail/read_db.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

use LPC\Mail\Db;
use LPC\Mail\MailError;
use LPC\Mail\Read\Actions;
use LPC\Mail\Read\ImageTrust;
use LPC\Mail\Read\Inbox;
use LPC\Mail\Read\Reader;
use LPC\Mail\Read\Screen;
use LPC\Mail\Read\ThreadList;
use LPC\Mail\Store;
use LPC\Mail\Sync\MailSync;
use LPC\Mail\Tests\FakeMailServer;

$pdo = mail_test_db();

section('Migration 310');
check('mail_image_trust exists (applied twice by the kit)', (bool) $pdo->query("SHOW TABLES LIKE 'mail_image_trust'")->fetch());

$admin = mail_test_user('Bruno Essomba', 'admin');
$awa = mail_test_user('Awa Mbarga', 'accountant');
$paul = mail_test_user('Paul Ndi', 'accountant');
$chantal = mail_test_user('Chantal Eyenga', 'accountant');

$server = FakeMailServer::install();
$server->addMailbox('contact@lpc.cm', 'secret');
$server->addMailbox('awa.mbarga@lpc.cm', 'secret');
$contact = mail_test_account('contact@lpc.cm', 'shared', $admin, 'secret', 'LPC Service client');
mail_test_member($contact, $awa, 'send');
mail_test_member($contact, $paul, 'read');
$own = mail_test_account('awa.mbarga@lpc.cm', 'personal', $awa);

$d = fn (string $ago) => (new DateTimeImmutable($ago))->format('D, d M Y H:i:s O');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(
    from: 'Promo Cameroun <news@promo.example>', subject: 'Offres de novembre', text: 'Nos offres',
    html: '<p>Nos <b>offres</b></p><img src="https://tracker.promo.example/o.gif" alt="Bannière"><img src="cid:logo@promo"><script>alert(1)</script><a href="javascript:x()">clic</a>',
    messageId: 'n1@promo.example', date: $d('-3 days'),
    attachments: [['name' => 'logo.png', 'mime' => 'image/png', 'content' => $png, 'inline' => true, 'cid' => 'logo@promo']],
));
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(
    from: 'Marc Etoundi <marc@epicerie.example>', subject: 'Facture T4', text: "Bonjour,\nmerci de renvoyer la facture du T4.",
    messageId: 'f1@epicerie.example', date: $d('-2 days'),
    attachments: [['name' => 'releve.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 essai']],
), ['\\Seen']);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Awa Client <awa@client.example>', subject: 'Livraison à Bonamoussadi', text: 'Pouvez-vous livrer jeudi ?', messageId: 'c1@client.example', date: $d('-30 hours')), ['\\Seen']);
$server->deliver('contact@lpc.cm', 'INBOX.Sent', FakeMailServer::message(from: 'LPC Service client <contact@lpc.cm>', to: 'awa@client.example', subject: 'RE: Livraison à Bonamoussadi', text: 'Oui, jeudi matin.', messageId: 'c2@lpc.cm', inReplyTo: 'c1@client.example', date: $d('-29 hours')), ['\\Seen']);
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Awa Client <awa@client.example>', subject: 'RE: Livraison à Bonamoussadi', text: "Parfait, merci.\n\nLe mar. 3 nov. 2026, LPC a écrit :\n> Oui, jeudi matin.", messageId: 'c3@client.example', inReplyTo: 'c2@lpc.cm', references: '<c1@client.example> <c2@lpc.cm>', date: $d('-5 hours')));
$server->deliver('contact@lpc.cm', 'INBOX.Junk', FakeMailServer::message(from: 'Arnaque <x@spam.example>', subject: 'Livraison gagnante', text: 'Cliquez', messageId: 's1@spam.example', date: $d('-1 day')));
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Ancien <old@client.example>', subject: 'Commande de 2025', text: 'Ancien message.', html: '<p>Ancien <img src="https://old.example/p.png"></p>', messageId: 'o1@client.example', date: $d('-14 months')), ['\\Seen']);
$server->deliver('awa.mbarga@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Paul Ndi <paul.ndi@lpc.cm>', to: 'awa.mbarga@lpc.cm', subject: 'Réunion de lundi', text: 'On se voit à 9 h.', messageId: 'p1@lpc.cm', date: $d('-2 hours')));
MailSync::make()->account($contact['id'], null, true);
MailSync::make()->account($own['id'], null, true);

$threadOf = fn (string $mid) => (int) Db::value('SELECT thread_id FROM mail_messages WHERE message_id = ? LIMIT 1', [$mid]);
$delivery = $threadOf('c1@client.example');
$promo = $threadOf('n1@promo.example');
$facture = $threadOf('f1@epicerie.example');
$reunion = $threadOf('p1@lpc.cm');

section('Where a link lands');
$in = Inbox::open($awa, []);
check('first mailbox: the person\'s own', $in->account['id'] === $own['id'] && $in->folder['role'] === 'inbox');
$in = Inbox::open($awa, ['thread' => $delivery]);
check('a thread link opens its mailbox, whatever box= says', $in->account['id'] === $contact['id'] && $in->thread['id'] === $delivery && $in->folder['role'] === 'inbox');
$in = Inbox::open($admin, ['box' => $own['id']]);
check('an admin cannot open someone\'s personal mailbox (falls back, with a notice)', $in->account['id'] === $contact['id'] && $in->notice !== null);
$in = Inbox::open($admin, ['thread' => $reunion]);
check('nor a conversation in it', $in->thread === null && $in->notice !== null);
$in = Inbox::open($chantal, ['thread' => $delivery]);
check('no access: no mailbox, a calm notice', $in->account === null && $in->thread === null && $in->notice !== null);
$sentOnly = $threadOf('c2@lpc.cm');
$in = Inbox::open($admin, ['box' => $contact['id'], 'folder' => 'sent']);
check('folder by role', $in->folder['role'] === 'sent' && $in->key($in->folder) === 'sent');
$in = Inbox::open($admin, ['box' => $contact['id'], 'folder' => (string) Store::folderByRole($contact['id'], 'spam')['id']]);
check('folder by id', $in->folder['role'] === 'spam');
$in = Inbox::open($admin, ['box' => $contact['id'], 'folder' => 'nonsense']);
check('unknown folder: the Inbox', $in->folder['role'] === 'inbox');

section('Conversation list');
$inbox = Inbox::open($admin, ['box' => $contact['id']]);
$list = ThreadList::folder($admin, $inbox->folder);
$byId = [];
foreach ($list['rows'] as $r) {
    $byId[$r['id']] = $r;
}
check('the Inbox\'s conversations of the last 12 months, newest first', $list['total'] === 3 && $list['rows'][0]['id'] === $delivery, json_encode(array_column($list['rows'], 'subject')));
check('conversation with a reply in Envoyés: counted over the Inbox', $byId[$delivery]['row_count'] === 2 && $byId[$delivery]['message_count'] === 3);
check('unread badge', $byId[$delivery]['row_unread'] === 1 && $byId[$facture]['row_unread'] === 0);
check('attachment badge (inline pictures don\'t count)', $byId[$facture]['row_attach'] && !$byId[$promo]['row_attach']);
check('older than 12 months is not in the list', !in_array('Commande de 2025', array_column($list['rows'], 'subject'), true));
check('unread filter', array_column(ThreadList::folder($admin, $inbox->folder, 'unread')['rows'], 'id') == [$delivery, $promo]);
Db::exec("UPDATE mail_threads SET assigned_to = ?, status = 'done' WHERE id = ?", [$admin, $facture]);
check('Assignées à moi (shared)', array_column(ThreadList::folder($admin, $inbox->folder, 'mine')['rows'], 'id') == [$facture]);
check('Traitées / Ouvertes (shared)', array_column(ThreadList::folder($admin, $inbox->folder, 'done')['rows'], 'id') == [$facture] && count(ThreadList::folder($admin, $inbox->folder, 'open')['rows']) === 2);
$ownInbox = Inbox::open($awa, []);
check('no teamwork filters on a personal mailbox', ThreadList::filtersFor($own) === ['all', 'unread'] && ThreadList::folder($awa, $ownInbox->folder, 'done')['total'] === 1);
check('a page past the end comes back to the last one', ThreadList::folder($admin, $inbox->folder, 'all', 9)['page'] === 1);
check('no access: refused', throws(fn () => ThreadList::folder($chantal, $inbox->folder)) instanceof MailError);
$html = Screen::list($inbox, '', 'all', 1, $delivery)['html'];
check('list rows: link with box, folder and thread; active and unread marks', str_contains($html, 'href="?box=' . $contact['id'] . '&amp;folder=inbox&amp;thread=' . $delivery . '"') && str_contains($html, 'is-unread is-active'), substr($html, 0, 400));
check('list: Traitée pill and assignee on a shared mailbox', str_contains($html, 'Traitée') && str_contains($html, '> Vous</span>'));
check('list: Charger le courrier plus ancien on the last page', str_contains($html, 'data-action="older"'));
Db::exec("UPDATE mail_threads SET assigned_to = NULL, status = 'open' WHERE id = ?", [$facture]);

section('Search');
$s = ThreadList::search($admin, $contact, 'livraison', $inbox->folder);
check('FULLTEXT finds a word in the subject (Indésirables left out)', array_column($s['rows'], 'id') == [$delivery], json_encode(array_column($s['rows'], 'subject')));
check('a search row says which folders hold it', in_array(Store::folderByRole($contact['id'], 'sent')['id'], $s['rows'][0]['row_folders'], true));
check('a short word (T4) is found word by word', array_column(ThreadList::search($admin, $contact, 'T4', $inbox->folder)['rows'], 'id') == [$facture]);
check('de: looks at the sender only', array_column(ThreadList::search($admin, $contact, 'de:marc', $inbox->folder)['rows'], 'id') == [$facture] && ThreadList::search($admin, $contact, 'de:facture', $inbox->folder)['total'] === 0);
check('every word must match', ThreadList::search($admin, $contact, 'livraison facture', $inbox->folder)['total'] === 0);
$spam = Store::folderByRole($contact['id'], 'spam');
check('inside Indésirables, its own mail is searched', ThreadList::search($admin, $contact, 'gagnante', $spam)['total'] === 1 && ThreadList::search($admin, $contact, 'gagnante', $inbox->folder)['total'] === 0);
check('words with % or _ are plain text', ThreadList::search($admin, $contact, '100%_', $inbox->folder)['total'] === 0);
check('search needs read access', throws(fn () => ThreadList::search($chantal, $contact, 'livraison')) instanceof MailError);
$html = Screen::list($inbox, 'rien du tout', 'all', 1)['html'];
check('nothing found: say so and offer the server search', str_contains($html, 'Rien dans la copie de bureau') && str_contains($html, 'data-action="server-search"'));

section('Reader: opening marks read for everyone, with "Ouvert par"');
$inbox = Inbox::open($paul, ['thread' => $delivery]);
$out = Screen::thread($inbox, $inbox->thread);
$rows = Store::threadMessages($delivery);
$c3 = array_values(array_filter($rows, fn ($m) => $m['message_id'] === 'c3@client.example'))[0];
check('three messages, one row each, oldest first', $out['thread']['count'] === 3 && substr_count($out['messages'], '<article class="mr-msg') === 3);
check('the unread one and the latest are open, the others folded', $out['thread']['open'] === [$c3['id']] && substr_count($out['messages'], 'mr-msg is-open') === 1);
check('marked read in bureau (Lecture may open)', $c3['is_seen'] && $c3['seen_by'] === $paul);
check('and on the server (\\Seen)', in_array('\\Seen', $server->folder('contact@lpc.cm', 'INBOX')[$c3['uid']]['flags'], true));
check('"Ouvert par vous" on a shared mailbox', str_contains($out['messages'], '<b>Ouvert par</b> vous, '));
$adminView = Screen::thread(Inbox::open($admin, ['thread' => $delivery]), Store::thread($delivery));
check('others see who opened it first', str_contains($adminView['messages'], '<b>Ouvert par</b> Paul, ') && Store::message($c3['id'])['seen_by'] === $paul);
check('the sent reply is not "opened by" anyone', Store::message(array_values(array_filter($rows, fn ($m) => $m['message_id'] === 'c2@lpc.cm'))[0]['id'])['seen_by'] === null);
check('the quoted text is folded', str_contains($out['messages'], 'Afficher le texte cité'));
check('Lecture: no archive, no reply', !str_contains($out['head'], 'data-action="archive"') && !str_contains($out['head'], 'data-compose='));
check('Lecture: may mark unread', str_contains($out['head'], 'data-action="unread"'));
$awaView = Screen::thread(Inbox::open($awa, ['thread' => $delivery]), Store::thread($delivery), null, [], false, false);
check('Lecture et envoi: archive, move, reply on the latest', str_contains($awaView['head'], 'data-action="archive"') && str_contains($awaView['head'], 'data-move') && str_contains($awaView['head'], 'data-compose="reply" data-message="' . $c3['id'] . '"'));
$ownView = Screen::thread(Inbox::open($awa, ['thread' => $reunion]), Store::thread($reunion));
check('a personal mailbox shows no "Ouvert par"', !str_contains($ownView['messages'], 'Ouvert par'));
check('a folded message opens alone', str_contains(Screen::message(Inbox::open($admin, ['thread' => $delivery]), $rows[0]['id'])['html'], 'mr-msg is-open'));
check('a message of another mailbox is refused', throws(fn () => Screen::message(Inbox::open($admin, ['box' => $contact['id']]), Store::threadMessages($reunion)[0]['id'])) instanceof MailError);

section('Reader: HTML in a sandboxed frame, pictures held back, files');
$inbox = Inbox::open($admin, ['thread' => $promo]);
$out = Screen::thread($inbox, $inbox->thread);
$msg = $out['messages'];
$promoMessage = Store::threadMessages($promo)[0];
check('HTML only inside an iframe sandbox without scripts', str_contains($msg, 'sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"') && !str_contains($msg, 'allow-scripts'));
check('no <script> anywhere, even escaped in srcdoc', !preg_match('/<script|&lt;script/i', $msg));
check('javascript: link gone', !str_contains($msg, 'javascript:'));
check('remote picture held back, offer to show it', !str_contains($msg, 'tracker.promo.example') && str_contains($msg, 'data-action="images"') && str_contains($msg, 'data-action="trust"'));
check('cid: picture through read_file.php', str_contains($msg, 'read_file.php?m=' . $promoMessage['id'] . '&amp;amp;cid=logo%40promo'), $msg);
check('an inline picture is not listed as an attachment', !str_contains($msg, 'mr-files'));
$shown = Screen::message($inbox, $promoMessage['id'], true, false)['html'];
check('Afficher les images: this message, this time', str_contains($shown, 'tracker.promo.example') && !str_contains($shown, 'data-action="images"'));
$facView = Screen::thread(Inbox::open($admin, ['thread' => $facture]), Store::thread($facture));
$attachment = Store::attachments(Store::threadMessages($facture)[0]['id'])[0];
check('attachments to open and to download', str_contains($facView['messages'], 'read_file.php?a=' . $attachment['id'] . '&amp;view=1') && str_contains($facView['messages'], 'download aria-label="Télécharger releve.pdf"'));
check('PDF opens in the browser, the rest downloads', in_array('application/pdf', Reader::VIEWABLE, true) && !in_array('text/html', Reader::VIEWABLE, true) && !in_array('image/svg+xml', Reader::VIEWABLE, true));
check('the cid: picture reads back from the server, a picture', (\LPC\Mail\MailFetch::make()->inline($admin, $promoMessage['id'], 'logo@promo')['content'] ?? '') === $png);

section('Toujours pour cet expéditeur');
check('only a valid address', !ImageTrust::trust($admin, 'pas une adresse') && !ImageTrust::trust($admin, ''));
check('trusted once, kept per person', ImageTrust::trust($admin, 'News@Promo.example') && ImageTrust::trust($admin, 'news@promo.example') && ImageTrust::trusted($admin, ['NEWS@promo.example']) === ['news@promo.example']);
$again = Screen::thread(Inbox::open($admin, ['thread' => $promo]), Store::thread($promo));
check('the sender\'s pictures now load for this person', str_contains($again['messages'], 'tracker.promo.example') && !str_contains($again['messages'], 'data-action="images"'));
$paulView = Screen::thread(Inbox::open($paul, ['thread' => $promo]), Store::thread($promo));
check('not for anyone else', !str_contains($paulView['messages'], 'tracker.promo.example') && ImageTrust::trusted($paul, ['news@promo.example']) === []);

section('Toolbar actions');
$inbox = Inbox::open($paul, ['thread' => $facture]);
check('Lecture cannot archive', throws(fn () => Actions::run($inbox, $inbox->thread, 'archive', false)) instanceof MailError);
$inbox = Inbox::open($awa, ['thread' => $facture]);
$r = Actions::run($inbox, $inbox->thread, 'archive', false);
$archive = Store::folderByRole($contact['id'], 'archive');
$fm = Store::threadMessages($facture)[0];
check('archive: on the server and in bureau (Archives created)', $r['message'] === 'Conversation archivée.' && $archive && $fm['folder_id'] === $archive['id'] && count($server->folder('contact@lpc.cm', $archive['path'])) === 1);
check('it left the folder, and can be undone', $r['left'] && $r['undo'] === ['ids' => [$fm['id']], 'folder' => $inbox->folder['id']]);
check('Annuler brings it back', str_contains(Actions::undo($inbox, $r['undo']['ids'], $r['undo']['folder']), 'Boîte de réception') && Store::threadMessages($facture)[0]['folder_id'] === $inbox->folder['id']);
check('Annuler with another mailbox\'s messages does nothing', throws(fn () => Actions::undo($inbox, [Store::threadMessages($reunion)[0]['id']], $inbox->folder['id'])) instanceof MailError);
$r = Actions::run($inbox, $inbox->thread, 'flag', false);
check('suivi', $r['message'] === 'Marquée pour suivi.' && Store::threadMessages($facture)[0]['is_flagged'] && ThreadList::folder($awa, $inbox->folder)['rows'][1]['row_flagged']);
Actions::run($inbox, $inbox->thread, 'unflag', false);
check('suivi retiré', !Store::threadMessages($facture)[0]['is_flagged']);
$r = Actions::run($inbox, $inbox->thread, 'unread', false);
check('non lue (the latest message)', !Store::threadMessages($facture)[0]['is_seen'] && !$r['left']);
check('delete for good only from Corbeille or Indésirables', throws(fn () => Actions::run($inbox, $inbox->thread, 'delete', false)) instanceof MailError);
check('unknown action refused', throws(fn () => Actions::run($inbox, $inbox->thread, 'explode', false)) instanceof MailError);
check('a folder of another mailbox refused', throws(fn () => Actions::run($inbox, $inbox->thread, 'move:' . Store::folderByRole($own['id'], 'inbox')['id'], false)) instanceof MailError);
$r = Actions::run($inbox, $inbox->thread, 'trash', false);
$trash = Store::folderByRole($contact['id'], 'trash');
check('corbeille', $r['message'] === 'Conversation placée dans la Corbeille.' && Store::threadMessages($facture)[0]['folder_id'] === $trash['id']);
$inTrash = Inbox::open($awa, ['box' => $contact['id'], 'folder' => 'trash', 'thread' => $facture]);
check('the Corbeille toolbar offers Supprimer définitivement', str_contains(Screen::thread($inTrash, $inTrash->thread, null, [], false, false)['head'], 'data-action="delete"'));
$r = Actions::run($inTrash, $inTrash->thread, 'delete', false);
check('deleted for good: gone from the server and bureau', $r['left'] && Store::thread($facture) === null && $server->folder('contact@lpc.cm', $trash['path']) === []);

section('Vider Indésirables');
$inSpam = Inbox::open($awa, ['box' => $contact['id'], 'folder' => 'spam']);
check('Lecture cannot empty', throws(fn () => Actions::emptyFolder(Inbox::open($paul, ['box' => $contact['id'], 'folder' => 'spam']), $spam)) instanceof MailError);
check('emptied, on the server too', Actions::emptyFolder($inSpam, $spam) === 'Indésirables est vide.' && $server->folder('contact@lpc.cm', 'INBOX.Junk') === [] && ThreadList::folder($awa, $spam)['total'] === 0);
check('the Inbox can never be emptied', throws(fn () => Actions::emptyFolder($inSpam, Store::folderByRole($contact['id'], 'inbox'))) instanceof MailError);

section('Older mail, read live');
$inbox = Inbox::open($admin, ['box' => $contact['id']]);
$older = \LPC\Mail\MailFetch::make()->older($admin, $inbox->folder['id']);
check('older than 12 months, from the server', count($older['items']) === 1 && $older['items'][0]['subject'] === 'Commande de 2025');
$row = Reader::liveRow($older['items'][0]);
check('a live row', $row['from'] === 'Ancien' && $row['is_seen'] === true);
$live = Reader::live($admin, $inbox->folder, $older['items'][0]['uid'], false, 'Boîte de réception');
check('a live message: safe frame, picture held back', $live['body']['kind'] === 'html' && $live['body']['blocked'] === 1 && !str_contains($live['body']['doc'], 'old.example'));
check('a live message nobody else may read', throws(fn () => Reader::live($chantal, $inbox->folder, $older['items'][0]['uid'], false, 'x')) instanceof MailError);
check('a live message that is gone', Reader::live($admin, $inbox->folder, 99999, false, 'x') === null);
$found = \LPC\Mail\MailFetch::make()->searchServer($admin, $inbox->folder['id'], 'Ancien message');
check('server search reaches past 12 months', count($found) === 1);

section('Counts the page polls');
$before = $inbox->counts();
check('folders, mailboxes and the sidebar count', isset($before['folders'][0]['unread'], $before['boxes'][$contact['id']]) && is_int($before['nav']));
$server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(from: 'Nouveau <n@client.example>', subject: 'Nouvelle commande', text: 'Bonjour', messageId: 'new@client.example'));
MailSync::make()->account($contact['id']);
$after = Inbox::open($admin, ['box' => $contact['id']])->counts();
check('new mail changes the stamp', $after['stamp'] !== $before['stamp']);
check('and the Inbox count', $after['boxes'][$contact['id']] === $before['boxes'][$contact['id']] + 1);

mail_test_done();
