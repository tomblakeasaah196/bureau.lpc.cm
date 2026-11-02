<?php

/*
| A one-connection IMAP server that plays a script, for scripts/tests/mail/mail_wire.test.php (ported from JBS HQ).
|   php scripted_imap_server.php <script.json>
| Prints the port it listens on, accepts one client, sends the greeting, then for each step reads one whole
| command (answering "+ go ahead" to synchronising literals {n}), checks it against the step's regex and sends
| the step's lines with TAG replaced by the command's tag. Exits after the last step or on a mismatch
| (writing "MISMATCH" to stderr).
*/

$script = json_decode(file_get_contents($argv[1]), true);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (! $server) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$client = stream_socket_accept($server, 15);
if (! $client) {
    exit(1);
}
stream_set_timeout($client, 10);
fwrite($client, $script['greeting'] . "\r\n");

foreach ($script['steps'] as $step) {
    $command = '';
    while (true) {
        $line = fgets($client);
        if ($line === false) {
            exit(0);
        }
        $line = rtrim($line, "\r\n");
        if (preg_match('/\{(\d+)(\+?)\}$/', $line, $m)) {
            $command .= $line . "\r\n";
            if ($m[2] === '') {
                fwrite($client, "+ go ahead\r\n");
            }
            $command .= fread_all($client, (int) $m[1]);
            continue;
        }
        $command .= $line;
        break;
    }
    [$tag, $rest] = explode(' ', $command, 2) + [1 => ''];
    if (! preg_match('#' . $step['expect'] . '#s', $rest)) {
        fwrite(STDERR, "MISMATCH: expected {$step['expect']}, got {$rest}\n");
        fwrite($client, "{$tag} BAD unexpected\r\n");
        exit(2);
    }
    foreach ($step['reply'] as $out) {
        fwrite($client, str_replace('TAG', $tag, $out));
        if (! str_ends_with($out, "\r\n") && ! preg_match('/\{\d+\}$/', $out)) {
            fwrite($client, "\r\n");
        }
    }
}
usleep(200000);

function fread_all($stream, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }

    return $data;
}
