<?php

declare(strict_types=1);

namespace RustServerWiper\Http;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RustServerWiper\Services\Paths;
use RustServerWiper\Services\Recurrence;
use Throwable;

final class WipeInput
{
    public static function parse(Request $request, bool $immediate = false): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:191', 'timezone' => 'required|timezone:all',
            'enabled' => 'required|boolean', 'time' => 'nullable|date_format:Y-m-d\TH:i',
            'repeat' => 'present|array|max:35', 'repeat.*' => ['string', 'distinct', 'regex:/^[1-5] [1-7] ([01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'payload' => 'required|array:name,description,size,seed,random_seed,random_level,level,files,blueprints,identity_path,commands,backup,backup_timeout,readiness,map_mode,exclude_recent,wait_players,max_deferral,deadline_action,unknown_players',
            'payload.name' => 'nullable|string|max:191', 'payload.description' => 'nullable|string|max:10000',
            'payload.size' => 'nullable|integer|min:1000|max:10000', 'payload.seed' => 'nullable|integer|min:0|max:2147483647',
            'payload.random_seed' => 'required|boolean', 'payload.random_level' => 'required|boolean',
            'payload.level' => 'nullable|url:http,https|max:2000', 'payload.files' => 'nullable|string|max:20000',
            'payload.blueprints' => 'required|boolean', 'payload.identity_path' => 'required|string|max:1000',
            'payload.commands' => 'present|array|max:20', 'payload.commands.*' => 'array:minutes,command',
            'payload.commands.*.minutes' => 'required|integer|min:1|max:1440',
            'payload.commands.*.command' => 'required|string|max:2000|not_regex:/[\r\n\x00]/',
            'payload.backup' => 'sometimes|boolean', 'payload.backup_timeout' => 'sometimes|integer|min:30|max:600',
            'payload.readiness' => 'sometimes|in:none,wings,rcon', 'payload.map_mode' => 'sometimes|in:random,ordered,weighted', 'payload.exclude_recent' => 'sometimes|integer|min:0|max:20',
            'payload.wait_players' => 'sometimes|boolean', 'payload.max_deferral' => 'sometimes|integer|min:5|max:1440',
            'payload.deadline_action' => 'sometimes|in:cancel,proceed', 'payload.unknown_players' => 'sometimes|in:cancel,defer,proceed',
        ]);
        $payload = $data['payload'];
        if (($payload['random_seed'] && isset($payload['seed'])) ||
            ($payload['random_level'] && ! empty($payload['level'])) ||
            (($payload['random_level'] || ! empty($payload['level'])) && ($payload['random_seed'] || isset($payload['seed']) || isset($payload['size'])))) {
            throw ValidationException::withMessages(['payload' => 'Choose either procedural seed/size or a custom map.']);
        }
        try {
            Paths::normalize($payload['identity_path'], false);
            $patterns = Paths::patterns($payload);
            if (count($patterns) > 200) {
                throw new \InvalidArgumentException('Use at most 200 file patterns.');
            }
            if (! $patterns) {
                throw new \InvalidArgumentException('Select wipe files or enable blueprint deletion.');
            }
            if ($immediate) {
                $next = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            } elseif ($data['repeat']) {
                if (! empty($data['time'])) {
                    throw new \InvalidArgumentException('Use a one-time date or monthly repeat rules, not both.');
                }
                $next = Recurrence::next($data['repeat'], $data['timezone'], new DateTimeImmutable('now'));
            } else {
                if (empty($data['time'])) {
                    throw new \InvalidArgumentException('Select a one-time date or monthly repeat rules.');
                }
                $next = Recurrence::localTime($data['time'], $data['timezone']);
                if ($next <= new DateTimeImmutable('now')) {
                    throw new \InvalidArgumentException('The scheduled time must be in the future.');
                }
            }
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['schedule' => $e->getMessage()]);
        }

        return ['label' => $data['label'], 'timezone' => $data['timezone'], 'payload' => $payload,
            'repeat' => $data['repeat'], 'enabled' => $data['enabled'], 'next_run_at' => $next];
    }
}
