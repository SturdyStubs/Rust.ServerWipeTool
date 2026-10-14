<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use RuntimeException;

final class WebRcon
{
    private float $deadline = 0;

    public static function frame(string $text, int $opcode = 1): string
    {
        $length = strlen($text);
        $mask = random_bytes(4);
        if ($length > 65535) {
            throw new RuntimeException('RCON request exceeds the frame limit.');
        }
        $header = chr(0x80 | $opcode).($length < 126 ? chr(0x80 | $length) : chr(0x80 | 126).pack('n', $length));
        $data = '';
        for ($i = 0; $i < $length; $i++) {
            $data .= $text[$i] ^ $mask[$i % 4];
        }

        return $header.$mask.$data;
    }

    private function read($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $remaining = $this->deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Rust RCON response timed out.');
            }
            $limit = min(5.0, $remaining);
            stream_set_timeout($socket, (int) $limit, (int) (($limit - (int) $limit) * 1000000));
            $part = fread($socket, $length - strlen($data));
            if ($part === false || $part === '') {
                throw new RuntimeException('Rust RCON response timed out or disconnected.');
            } $data .= $part;
        }

return $data;
    }

    private static function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($socket, $data);
            if (! $written) {
                throw new RuntimeException('Rust RCON connection failed.');
            } $data = substr($data, $written);
        }
    }

    public function players(string $host, int $port, string $password, bool $tls = false): int
    {
        if ($password === '') {
            throw new RuntimeException('Configure the Rust WebRCON password first.');
        }
        if (preg_match('/[\s\x00-\x1f\/]/', $host)) {
            throw new RuntimeException('Invalid RCON host.');
        }
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $authority = (str_contains($host, ':') ? "[$host]" : $host).":$port";
        $socket = @stream_socket_client(($tls ? 'tls://' : 'tcp://').$authority, $code, $message, 5, STREAM_CLIENT_CONNECT, $context);
        if (! $socket) {
            throw new RuntimeException('Could not connect to Rust WebRCON. Check its port and firewall.');
        }
        try {
            $this->deadline = microtime(true) + 15;
            stream_set_timeout($socket, 5);
            $key = base64_encode(random_bytes(16));
            self::write($socket, 'GET /'.rawurlencode($password)." HTTP/1.1\r\nHost: $authority\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
            $headers = '';
            while (! str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 8192) {
                $headers .= $this->read($socket, 1);
            }
            $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (! preg_match('~^HTTP/1\.[01] 101\b~', $headers) || ! preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($expected, '/').'\s*$/mi', $headers)) {
                throw new RuntimeException('Rust WebRCON rejected the connection.');
            }
            self::write($socket, self::frame(json_encode(['Identifier' => 1001, 'Message' => 'playerlist', 'Name' => 'RustServerWiper'], JSON_THROW_ON_ERROR)));
            $deadline = microtime(true) + 10;
            $text = '';
            for ($frames = 0; $frames < 100 && microtime(true) < $deadline; $frames++) {
                $header = $this->read($socket, 2);
                $first = ord($header[0]);
                $second = ord($header[1]);
                $opcode = $first & 15;
                $length = $second & 127;
                if ($length === 126) {
                    $length = unpack('n', $this->read($socket, 2))[1];
                } elseif ($length === 127) {
                    $size = unpack('Nhigh/Nlow', $this->read($socket, 8));
                    if ($size['high'] !== 0) {
                        throw new RuntimeException('RCON response too large.');
                    } $length = $size['low'];
                }
                if ($length > 1024 * 1024) {
                    throw new RuntimeException('RCON response too large.');
                }
                if (($first & 0x70) || ($opcode >= 8 && ($length > 125 || ! ($first & 128)))) {
                    throw new RuntimeException('Invalid RCON websocket frame.');
                }
                $mask = ($second & 128) ? $this->read($socket, 4) : null;
                $payload = $this->read($socket, $length);
                if ($mask) {
                    for ($i = 0; $i < $length; $i++) {
                        $payload[$i] = $payload[$i] ^ $mask[$i % 4];
                    }
                }
                if ($opcode === 8) {
                    throw new RuntimeException('Rust closed the RCON connection.');
                }
                if ($opcode === 9) {
                    self::write($socket, self::frame($payload, 10));

                    continue;
                }
                if (! in_array($opcode, [0, 1], true)) {
                    continue;
                }
                if ($opcode === 1) {
                    $text = '';
                } $text .= $payload;
                if (strlen($text) > 1024 * 1024) {
                    throw new RuntimeException('RCON response too large.');
                }
                if (! ($first & 128)) {
                    continue;
                }
                $packet = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
                if (($packet['Identifier'] ?? null) !== 1001) {
                    continue;
                }
                $players = json_decode($packet['Message'] ?? '', true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($players) || ! array_is_list($players)) {
                    throw new RuntimeException('Rust returned an invalid player list.');
                }

                return count($players);
            }
            throw new RuntimeException('Rust WebRCON did not return the player list.');
        } finally {
            fclose($socket);
        }
    }
}
