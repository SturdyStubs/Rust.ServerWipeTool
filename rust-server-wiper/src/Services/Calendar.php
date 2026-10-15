<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use DateTimeImmutable;
use RustServerWiper\Models\Wipe;

final class Calendar
{
    public static function forServer(int $id): array
    {
        $events = [];
        $notes = [];
        $skipped = [];
        foreach (Wipe::query()->where('server_id', $id)->where('enabled', true)->whereNotNull('next_run_at')->get() as $wipe) {
            $next = DateTimeImmutable::createFromInterface($wipe->next_run_at);
            for ($i = 0; $next && $i < 12; $i++) {
                $events[] = ['wipe_id' => $wipe->id, 'label' => $wipe->label, 'at' => $next->format(DATE_ATOM), 'timezone' => $wipe->timezone];
                $next = Recurrence::next($wipe->repeat, $wipe->timezone, $next);
            }
            if (array_filter($wipe->repeat, fn ($rule) => preg_match('/^[1-5] /', $rule))) {
                $notes[] = ['wipe_id' => $wipe->id, 'message' => 'Months without the selected fifth weekday and nonexistent daylight-saving times are skipped.'];
            }
            foreach (Recurrence::skips($wipe->repeat, $wipe->timezone, DateTimeImmutable::createFromInterface(now('UTC'))) as $skip) {
                $skipped[] = ['wipe_id' => $wipe->id, 'label' => $wipe->label, 'timezone' => $wipe->timezone, ...$skip];
            }
        }
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));
        foreach ($events as $i => &$event) {
            $event['conflict'] = false;
            foreach ($events as $j => $other) {
                if ($j !== $i && $event['wipe_id'] !== $other['wipe_id'] && abs(strtotime($event['at']) - strtotime($other['at'])) < 1200) {
                    $event['conflict'] = true;
                    break;
                }
            }
        }

        return ['events' => array_slice($events, 0, 100), 'notes' => $notes, 'skipped' => array_slice($skipped, 0, 200), 'conflict_window_minutes' => 20];
    }
}
