<?php

declare(strict_types=1);

// Minimal HTTP/1.1 keep-alive echo server (php -S always answers "Connection: close").
// Usage: php keepalive_server.php <port>. Each response reports the connection number it arrived on.
$server = stream_socket_server('tcp://127.0.0.1:' . (int) ($argv[1] ?? 0));
if ($server === false) {
    exit(1);
}
$connection = 0;
while (($client = stream_socket_accept($server, -1)) !== false) {
    ++$connection;
    while (($head = stream_get_line($client, 65536, "\r\n\r\n")) !== false) {
        preg_match('/^(\S+) /', $head, $method);
        preg_match('/^content-type: *(.*)$/mi', $head, $type);
        $length = preg_match('/^content-length: *(\d+)/mi', $head, $matches) === 1 ? (int) $matches[1] : 0;
        $body = $length > 0 ? (string) stream_get_contents($client, $length) : '';
        $payload = json_encode([
            'connection' => $connection,
            'method' => $method[1] ?? '',
            'body' => $body,
            'content_type' => isset($type[1]) ? trim($type[1]) : null,
        ]);
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen((string) $payload) . "\r\nConnection: keep-alive\r\n\r\n" . $payload);
    }
    fclose($client);
}
