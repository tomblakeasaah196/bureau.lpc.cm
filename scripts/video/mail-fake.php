<?php
/**
 * scripts/video/mail-fake.php: Courrier on the filming copy, with nothing reaching a real server
 * (docs/TRAINING_VIDEOS.md §4.4). Loaded by scripts/video/router.php for every request of the copy, and by
 * scripts/video/mail.php, never on the live site (scripts/ is refused there, and this file refuses any
 * database but the copy's).
 *
 *   LPC_VIDEO_MAIL_STATE   a JSON file holding the fake mail server's mailboxes (VIDEO_HOME/mail-state.json):
 *                          every IMAP and SMTP call of the engine goes to \LPC\Mail\Tests\FakeMailServer
 *                          (the Courrier tests' own), whose state is read at the start of a request and
 *                          written back at its end. Mail "sent" lands in the recipients' fake mailboxes when
 *                          they are on it, and nowhere else.
 *   LPC_VIDEO_AI_REPLIES   a JSON file of written answers for Praxis AI (the owner's choice for the Courrier
 *                          video, 18/11/2026): [{"match": "words found in the request", "reply": "text", "json":
 *                          {...}}]. The first reply whose match is in the prompt answers; a request nothing
 *                          matches is answered "busy" and written to <file>.unmatched.jsonl, so a scene's
 *                          replies are written from what the app really asks. Nothing reaches Google.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

function video_mail_root(): string
{
    return rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
}

/** The copy's database name, from the env file the app reads: anything but bureau_video* is refused. */
function video_mail_guard(): void
{
    $env = getenv('LPC_ENV_PATH') ?: '';
    $db = '';
    if ($env !== '' && is_file($env)) {
        foreach (file($env) as $line) {
            if (preg_match('/^\s*DB_NAME\s*=\s*"?([^"\s]+)/', $line, $m)) {
                $db = $m[1];
            }
        }
    }
    if (!preg_match('/^bureau_video/', $db)) {
        throw new RuntimeException('The fake mail server runs only on the filming copy (DB_NAME bureau_video*).');
    }
}

/** Install the fake mail server and the AI stand-in for this process. Idempotent. */
function video_mail_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $state = getenv('LPC_VIDEO_MAIL_STATE') ?: '';
    $replies = getenv('LPC_VIDEO_AI_REPLIES') ?: '';
    if ($state === '' && $replies === '') {
        return;
    }
    video_mail_guard();
    $root = video_mail_root();
    require_once $root . '/includes/config/env.php';
    require_once $root . '/includes/mail/autoload.php';
    require_once $root . '/scripts/tests/mail/support/FakeImapSession.php';
    require_once $root . '/scripts/tests/mail/support/FakeMailServer.php';

    if ($state !== '') {
        $fake = new \LPC\Mail\Tests\FakeMailServer();
        if (is_file($state)) {
            $s = json_decode((string) file_get_contents($state), true) ?: [];
            $fake->mailboxes = $s['mailboxes'] ?? [];
        }
        \LPC\Mail\MailServer::use($fake);
        $GLOBALS['__video_mail_fake'] = $fake;
        register_shutdown_function(function () use ($fake, $state) {
            video_mail_route_sent($fake);
            file_put_contents($state, json_encode(['mailboxes' => $fake->mailboxes], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        });
    }

    if ($replies !== '') {
        \LPC\Mail\Ai\Gemini::fake(function (array $request) use ($replies): array {
            $all = is_file($replies) ? (json_decode((string) file_get_contents($replies), true) ?: []) : [];
            $asked = json_encode($request['body'] ?? [], JSON_UNESCAPED_UNICODE);
            foreach ($all as $r) {
                // `match`: one phrase, or a list of phrases that must all be in the request.
                $needles = array_filter(array_map('strval', (array) ($r['match'] ?? [])), 'strlen');
                $hit = $needles !== [];
                foreach ($needles as $needle) {
                    $hit = $hit && mb_stripos($asked, json_encode($needle, JSON_UNESCAPED_UNICODE) === false ? $needle : trim(json_encode($needle, JSON_UNESCAPED_UNICODE), '"')) !== false;
                }
                if ($hit) {
                    if (!empty($r['delay'])) {
                        usleep((int) ($r['delay'] * 1e6));
                    }
                    $text = isset($r['json']) ? json_encode($r['json'], JSON_UNESCAPED_UNICODE) : (string) ($r['reply'] ?? '');
                    // {{ref:words}} becomes [n], n being the number the app gave the message holding those words
                    // ("[3] De : …"), so a written answer cites what the search really returned. A reference to a
                    // message the app did not send is an error in the scene, never a citation to something else.
                    $prompt = (string) ($request['body']['contents'][0]['parts'][0]['text'] ?? '');
                    $text = preg_replace_callback('/\{\{ref:([^}]+)\}\}/u', function ($m) use ($prompt, $replies) {
                        foreach (preg_split('/\n(?=\[\d+\] )/u', $prompt) as $block) {
                            if (preg_match('/^\[(\d+)\] /u', $block, $n) && mb_stripos($block, $m[1]) !== false) {
                                return '[' . $n[1] . ']';
                            }
                        }
                        file_put_contents($replies . '.unmatched.jsonl', json_encode(['at' => date('c'), 'missing_ref' => $m[1]], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                        return '';
                    }, $text);
                    file_put_contents($replies . '.log.jsonl', json_encode(['at' => date('c'), 'match' => $r['match'], 'request' => $request['body'] ?? null, 'answer' => $text], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                    return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']]], JSON_UNESCAPED_UNICODE)];
                }
            }
            file_put_contents($replies . '.unmatched.jsonl', json_encode(['at' => date('c'), 'request' => $request['body'] ?? null], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            return ['status' => 503, 'body' => '{"error":{"status":"UNAVAILABLE"}}'];
        });
    }
}

/**
 * What the copy "sent" is delivered to the recipients that have a mailbox on the fake server, as their
 * mail server would, so a reply Daniel sends shows in the colleague's inbox. Everything else stays sent.
 */
function video_mail_route_sent(\LPC\Mail\Tests\FakeMailServer $fake): void
{
    foreach ($fake->sent as $i => $sent) {
        if (!empty($sent['__routed']) || !($sent['message'] ?? null) instanceof \LPC\Mail\Mime\Message) {
            continue;
        }
        $m = $sent['message'];
        $read = fn (string $prop) => (function () use ($prop) { return $this->{$prop}; })->call($m);
        $fmt = fn (array $list) => implode(', ', array_map(fn ($a) => \LPC\Mail\Mime\Message::formatAddress($a), $list));
        $addr = $m->getAddresses();
        $from = $m->getFrom();
        $id = trim((string) ($m->getMessageId() ?? ''), '<>');
        $built = \LPC\Mail\Tests\FakeMailServer::message(
            from: $from ? \LPC\Mail\Mime\Message::formatAddress($from) : (string) ($sent['account'] ?? ''),
            to: $fmt($addr['to']),
            subject: $m->getSubject(),
            text: $read('text'),
            html: $read('html'),
            messageId: $id !== '' ? $id : null,
            inReplyTo: ($r = $m->getHeader('In-Reply-To')) ? trim($r, '<> ') : null,
            references: $m->getHeader('References'),
            cc: $fmt($addr['cc']),
            attachments: array_values(array_map(fn ($a) => ['name' => $a['filename'], 'mime' => $a['mime'], 'content' => $a['content']],
                array_filter($read('attachments'), fn ($a) => empty($a['cid'])))),
        );
        foreach ((array) ($sent['recipients'] ?? []) as $address) {
            $address = strtolower($address);
            if (isset($fake->mailboxes[$address]) && $address !== strtolower((string) ($sent['account'] ?? ''))) {
                $fake->deliver($address, 'INBOX', $built);
            }
        }
        $fake->sent[$i]['__routed'] = true;
    }
}
