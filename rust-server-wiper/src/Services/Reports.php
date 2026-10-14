<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use RustServerWiper\Models\Run;

final class Reports
{
    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (! is_string($key) || ! preg_match('/password|token|secret|webhook|original_environment/i', $key)) {
                    $clean[$key] = self::redact($item);
                }
            }

            return $clean;
        }
        if (is_string($value)) {
            return preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~', '$1?[redacted]', $value);
        }

        return $value;
    }

    public static function export(Run $run): array
    {
        return self::redact(['id' => $run->id, 'server_id' => $run->server_id, 'label' => $run->label, 'status' => $run->status, 'phase' => $run->phase, 'created_by' => $run->created_by, 'schedule_updated_by' => $run->updated_by, 'due_at' => $run->due_at?->toAtomString(), 'started_at' => $run->started_at?->toAtomString(), 'finished_at' => $run->finished_at?->toAtomString(),
            'duration_seconds' => $run->started_at && $run->finished_at ? (int) $run->started_at->diffInSeconds($run->finished_at) : null, 'backup_uuid' => $run->backup_uuid, 'message' => $run->message, 'result' => $run->result, 'timeline' => $run->timeline]);
    }

    public static function phase(Run $run, string $phase, string $message, ?array $result = null): void
    {
        $timeline = $run->timeline ?? [];
        $timeline[] = ['phase' => $phase, 'at' => now('UTC')->toAtomString(), 'message' => $message];
        $run->update(['phase' => $phase, 'message' => $message, 'timeline' => $timeline, ...($result === null ? [] : ['result' => $result])]);
    }
}
