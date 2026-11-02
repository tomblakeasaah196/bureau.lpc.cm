<?php

/*
| A one-connection SMTP server that plays a script, for scripts/tests/mail/mail_wire.test.php.
|   php scripted_smtp_server.php <script.json>
| Prints the port it listens on, accepts one client, sends the greeting, then for each step reads one line
| (or, after DATA, everything up to the lone "."), checks it against the step's regex and sends the step's
| lines. Exits after the last step or on a mismatch (writing "MISMATCH" to stderr). Saves the DATA it got
| to <script.json>.data so the test can compare it.
*/

$script = json_decode(file_get_contents($argv[1]), true);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$client = stream_socket_accept($server, 15);
if (!$client) {
    exit(1);
}
stream_set_timeout($client, 10);
fwrite($client, $script['greeting'] . "\r\n");

foreach ($script['steps'] as $step) {
    if (!empty($step['data'])) {
        $data = '';
        while (($line = fgets($client)) !== false) {
            if ($line === ".\r\n") {
                break;
            }
            $data .= $line;
        }
        file_put_contents($argv[1] . '.data', $data);
        $got = '<DATA>';
    } else {
        $got = fgets($client);
        if ($got === false) {
            exit(0);
        }
        $got = rtrim($got, "\r\n");
    }
    if (!preg_match('#' . $step['expect'] . '#s', $got)) {
        fwrite(STDERR, "MISMATCH: expected {$step['expect']}, got {$got}\n");
        fwrite($client, "500 unexpected\r\n");
        exit(2);
    }
    foreach ($step['reply'] as $out) {
        fwrite($client, $out . "\r\n");
    }
}
usleep(200000);
