<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

final class Identity
{
    public static function detect(string $startup, array $environment): array
    {
        foreach ($environment as $key => $value) {
            $startup = str_replace('{{'.$key.'}}', $value, $startup);
        }
        $identity = null;
        $source = null;
        if (preg_match('/(?:\+|\b)server\.identity\s+(?:"([^"]+)"|\x27([^\x27]+)\x27|([^\s]+))/', $startup, $m)) {
            $identity = $m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? '');
            $source = 'startup server.identity';
        }
        if (! $identity) {
            foreach (['SERVER_IDENTITY', 'IDENTITY'] as $key) {
                if (! empty($environment[$key])) {
                    $identity = $environment[$key];
                    $source = $key;
                    break;
                }
            }
        }
        if (! $identity || ! preg_match('/^[a-zA-Z0-9_.-]+$/D', $identity) || in_array($identity, ['.', '..'], true)) {
            return ['path' => null, 'source' => null, 'message' => 'Identity could not be detected. Confirm the directory manually.'];
        }
        $path = '/server/'.$identity;

        return ['path' => $path, 'source' => $source, 'message' => 'Suggested from '.$source.'. Confirm it matches the running server.', 'patterns' => ["$path/*.map", "$path/*.sav", "$path/*.sav.*"]];
    }
}
