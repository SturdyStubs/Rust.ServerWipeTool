<?php

declare(strict_types=1);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (! $listener) {
    exit(1);
}
echo stream_socket_get_name($listener, false)."\n";
flush();
$socket = stream_socket_accept($listener, 5);
if (! $socket) {
    exit(2);
}
stream_set_timeout($socket, 5);
$headers = '';
while (! str_ends_with($headers, "\r\n\r\n")) {
    $headers .= fread($socket, 1);
}
preg_match('/Sec-WebSocket-Key:\s*([^\r\n]+)/i', $headers, $m);
$key = trim($m[1]);
$accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($socket, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
function readBytes($s, int $n): string
{
    $v = '';
    while (strlen($v) < $n) {
        $p = fread($s, $n - strlen($v));
        if (! $p) {
            exit(3);
        } $v .= $p;
    }

return $v;
}
$header = readBytes($socket, 2);
$length = ord($header[1]) & 127;
if ($length === 126) {
    $length = unpack('n', readBytes($socket, 2))[1];
}
$mask = readBytes($socket, 4);
$text = readBytes($socket, $length);
for ($i = 0; $i < $length; $i++) {
    $text[$i] = $text[$i] ^ $mask[$i % 4];
}
$request = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
if ($request['Message'] !== 'playerlist' || $request['Identifier'] !== 1001) {
    exit(4);
}
$response = json_encode(['Identifier' => 1001, 'Message' => json_encode([['SteamID' => '1'], ['SteamID' => '2']])]);
// Exercise ping handling and a fragmented text response.
fwrite($socket, chr(0x89).chr(1).'x');
$part1 = substr($response, 0, 20);
$part2 = substr($response, 20);
fwrite($socket, chr(0x01).chr(strlen($part1)).$part1);
fwrite($socket, chr(0x80).chr(strlen($part2)).$part2);
fclose($socket);
fclose($listener);
