<?php

declare(strict_types=1);

/*
 * A peer that never stops sending, in its own process (faster than the SDK reads, as a remote
 * one can be): prints its port, then for each connection, until <seconds> pass or it is gone:
 *   ws-pong:       completes a WebSocket handshake, then sends empty pong frames;
 *   ws-ping:       completes a WebSocket handshake, then sends pings and never reads (the pongs);
 *   http-continue: sends "HTTP/1.1 100 Continue" heads.
 * Plain TCP on 127.0.0.1. Usage: php flood-server.php ws-pong|ws-ping|http-continue <seconds>
 */

$mode = $argv[1] ?? '';
$seconds = (int) ($argv[2] ?? 10);
$server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
if ($server === false || !in_array($mode, ['ws-pong', 'ws-ping', 'http-continue'], true)) {
    fwrite(STDERR, "flood-server: {$message}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, (int) strrpos($name, ':') + 1), "\n";
$end = time() + $seconds;
while (time() < $end && ($client = @stream_socket_accept($server, max(1, $end - time()))) !== false) {
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && strlen($head) < 65536) {
        $chunk = fread($client, 4096);
        if ($chunk === false || $chunk === '') {
            continue 2;
        }
        $head .= $chunk;
    }
    if ($mode === 'ws-pong' || $mode === 'ws-ping') {
        preg_match('/^Sec-WebSocket-Key: *(\S+)/mi', $head, $key);
        fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . base64_encode(sha1(($key[1] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)) . "\r\n\r\n");
        // Pongs empty; pings of 125 bytes, the most a control frame holds, so that the pongs that
        // answer them fill the connection with 20 times fewer frames than empty ones would.
        $flood = $mode === 'ws-pong' ? str_repeat("\x8a\x00", 32768) : str_repeat("\x89\x7d" . str_repeat('p', 125), 512);
    } else {
        $flood = str_repeat("HTTP/1.1 100 Continue\r\n\r\n", 2048);
    }
    while (time() < $end && @fwrite($client, $flood) !== false) {
    }
    fclose($client);
}
