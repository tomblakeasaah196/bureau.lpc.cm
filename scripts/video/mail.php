<?php
/**
 * scripts/video/mail.php: Courrier's ground on the filming copy (docs/TRAINING_VIDEOS.md §4.4).
 * CLI only, the copy only (mail-fake.php refuses any other database). ground.js `mail()` runs it with
 * LPC_ENV_PATH, LPC_VIDEO_MAIL_STATE and LPC_VIDEO_AI_REPLIES set.
 *
 *   php scripts/video/mail.php seed <seed.php>   clear the copy's mail (a copy of real people's mail: never
 *                                                filmed), set Praxis AI's placeholder key, empty the fake
 *                                                server, then run the scene's seed file, which adds mailboxes,
 *                                                delivers the written examples and connects what must exist
 *                                                before the camera rolls
 *   php scripts/video/mail.php sync              the cron's sync, for every mailbox, now
 *   php scripts/video/mail.php send              the cron's outbox run (what is due: a sent message whose
 *                                                « Annuler l'envoi » window has passed, a scheduled one)
 *   php scripts/video/mail.php deliver <json>    a message arriving now: {"to","folder","from","subject","text",
 *                                                "html","date","attachments"}; then sync, so the bell rings
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Africa/Douala');
require_once __DIR__ . '/mail-fake.php';
video_mail_guard();
$root = video_mail_root();
require_once $root . '/includes/bootstrap.php';
video_mail_install();
require_once $root . '/includes/mail/autoload.php';

use LPC\Mail\Compose\OutboxSender;
use LPC\Mail\Sync\MailSync;
use LPC\Mail\Tests\FakeMailServer;

$cmd = $argv[1] ?? '';
/** @var FakeMailServer $fake */
$fake = $GLOBALS['__video_mail_fake'] ?? null;
if (!$fake) {
    fwrite(STDERR, "LPC_VIDEO_MAIL_STATE is not set.\n");
    exit(2);
}
$db = Database::getInstance()->getConnection();

/** A date N hours ago, as a mail header writes it. */
function video_mail_ago(float $hours): string
{
    return (new DateTimeImmutable('-' . (int) round($hours * 60) . ' minutes'))->format('D, d M Y H:i:s O');
}

function video_mail_sync_all(): void
{
    foreach (\LPC\Mail\Db::column('SELECT id FROM mail_accounts') as $id) {
        MailSync::make()->account((int) $id, 60.0, true);
    }
}

switch ($cmd) {
    case 'seed':
        $file = $argv[2] ?? '';
        if (!is_file($file)) {
            fwrite(STDERR, "seed needs a seed file.\n");
            exit(2);
        }
        // The copy holds live mail (people's real messages, cached): it is never filmed, so it goes.
        $tables = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name LIKE 'mail\\_%' AND table_name <> 'mail_settings'")->fetchAll(PDO::FETCH_COLUMN);
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $db->exec("DELETE FROM `{$t}`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $db->exec("DELETE FROM notifications WHERE related_url LIKE '/modules/mail/%'");
        $db->exec("DELETE FROM mail_settings WHERE setting_key LIKE 'ai.usage.%'");
        // Praxis AI answers from the written replies (LPC_VIDEO_AI_REPLIES); it still needs a key to be "on".
        // Live's key is encrypted with live's APP_ENCRYPTION_KEY, which the copy does not have.
        \LPC\Mail\Ai\Settings::save(1, 'video-stand-in-no-real-key-0000', \LPC\Mail\Ai\Settings::model());
        $fake->mailboxes = [];
        $fake->sent = [];
        require $file;
        echo "seeded: " . implode(', ', array_keys($fake->mailboxes)) . "\n";
        break;

    case 'sync':
        video_mail_sync_all();
        echo "synced\n";
        break;

    case 'send':
        $r = OutboxSender::make()->due();
        echo json_encode($r) . "\n";
        break;

    case 'deliver':
        $m = json_decode((string) ($argv[2] ?? ''), true);
        if (!is_array($m) || empty($m['to'])) {
            fwrite(STDERR, "deliver needs a JSON message with \"to\".\n");
            exit(2);
        }
        $fake->deliver($m['to'], $m['folder'] ?? 'INBOX', FakeMailServer::message(
            from: $m['from'], to: $m['to'], subject: $m['subject'], text: $m['text'] ?? null, html: $m['html'] ?? null,
            date: video_mail_ago(0), attachments: $m['attachments'] ?? []));
        video_mail_sync_all();
        echo "delivered\n";
        break;

    default:
        fwrite(STDERR, "usage: mail.php seed <file> | sync | send | deliver <json>\n");
        exit(2);
}
