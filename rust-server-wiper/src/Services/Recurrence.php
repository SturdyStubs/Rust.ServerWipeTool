<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Recurrence
{
    /** Monthly: "1 4 14:00"; weekly: "weekly 4 14:00"; fortnightly: "fortnightly 2026-10-01 14:00". */
    public static function next(array $rules, string $timezone, DateTimeImmutable $after): ?DateTimeImmutable
    {
        $zone = new DateTimeZone($timezone);
        $local = $after->setTimezone($zone);
        $best = null;
        foreach ($rules as $rule) {
            $candidate = null;
            if (preg_match('/^(weekly|fortnightly) (\S+) ([0-2][0-9]:[0-5][0-9])$/D', $rule, $m)) {
                if ($m[1] === 'weekly' && ! preg_match('/^[1-7]$/D', $m[2])) {
                    throw new InvalidArgumentException('Invalid weekly weekday.');
                }
                $anchor = $m[1] === 'fortnightly' ? self::localTime($m[2].'T'.$m[3], $timezone)->setTimezone($zone) : null;
                $day = $anchor && $anchor->format('Y-m-d') > $local->format('Y-m-d') ? $anchor->setTime(12, 0) : $local->setTime(12, 0);
                for ($i = 0; $i < 740; $i++) {
                    $date = $day->modify("+$i days");
                    if ($anchor ? ($date->format('Y-m-d') < $anchor->format('Y-m-d') || (int) $anchor->setTime(12, 0)->diff($date)->format('%a') % 14 !== 0) : (int) $date->format('N') !== (int) $m[2]) {
                        continue;
                    }
                    try {
                        $value = self::localTime($date->format('Y-m-d').'T'.$m[3], $timezone);
                    } catch (InvalidArgumentException) {
                        continue; // Skip nonexistent wall-clock times without moving the anchor.
                    }
                    if ($value > $after) {
                        $candidate = $value;
                        break;
                    }
                }
            } else {
                $month = $local->modify('first day of this month')->setTime(12, 0);
                for ($offset = 0; $offset < 24; $offset++) {
                    $value = self::inMonth($rule, $month->modify("+$offset months"), $zone)['date'];
                    if ($value && $value > $after) {
                        $candidate = $value;
                        break;
                    }
                }
            }
            if ($candidate === null) {
                throw new InvalidArgumentException('No recurrence found within two years.');
            }
            if ($best === null || $candidate < $best) {
                $best = $candidate;
            }
        }

        return $best?->setTimezone(new DateTimeZone('UTC'));
    }

    private static function inMonth(string $rule, DateTimeImmutable $first, DateTimeZone $zone): array
    {
        if (! preg_match('/^([1-5]) ([1-7]) ([01][0-9]|2[0-3]):([0-5][0-9])$/D', $rule, $m)) {
            throw new InvalidArgumentException('Invalid monthly recurrence rule.');
        }
        $day = 1 + ((int) $m[2] - (int) $first->format('N') + 7) % 7 + ((int) $m[1] - 1) * 7;
        if ($day > (int) $first->format('t')) {
            return ['date' => null, 'reason' => 'This month does not contain the selected fifth weekday.', 'local' => $first->format('Y-m')];
        }
        $stamp = $first->format('Y-m-').sprintf('%02d %s:%s', $day, $m[3], $m[4]);
        $date = new DateTimeImmutable($stamp, $zone);

        return $date->format('Y-m-d H:i') === $stamp ? ['date' => $date] : ['date' => null, 'reason' => 'Local time does not exist during the daylight-saving jump.', 'local' => $stamp];
    }

    public static function skips(array $rules, string $timezone, DateTimeImmutable $after): array
    {
        $zone = new DateTimeZone($timezone);
        $month = $after->setTimezone($zone)->modify('first day of this month')->setTime(12, 0);
        $skips = [];
        for ($i = 0; $i < 12; $i++) {
            foreach ($rules as $rule) {
                if (str_starts_with($rule, 'weekly ') || str_starts_with($rule, 'fortnightly ')) {
                    continue;
                }
                $item = self::inMonth($rule, $month->modify("+$i months"), $zone);
                if (! $item['date']) {
                    $skips[] = ['local' => $item['local'], 'rule' => $rule, 'reason' => $item['reason']];
                }
            }
        }

        return $skips;
    }

    public static function localTime(string $value, string $timezone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone($timezone));
        if (! $date || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Invalid local time, including a nonexistent daylight-saving time.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
