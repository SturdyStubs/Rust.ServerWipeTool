<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Recurrence
{
    /** Rules are "ordinal ISO-weekday HH:MM", e.g. "1 4 14:00" for first Thursday. */
    public static function next(array $rules, string $timezone, DateTimeImmutable $after): ?DateTimeImmutable
    {
        if (! $rules) {
            return null;
        }
        $zone = new DateTimeZone($timezone);
        $local = $after->setTimezone($zone);
        $month = $local->modify('first day of this month')->setTime(12, 0);
        $best = null;
        for ($offset = 0; $offset < 24; $offset++) {
            $first = $month->modify("+$offset months");
            foreach ($rules as $rule) {
                $candidate = self::inMonth($rule, $first, $zone)['date'];
                if (! $candidate || $candidate <= $after) {
                    continue;
                }
                if ($best === null || $candidate < $best) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                return $best->setTimezone(new DateTimeZone('UTC'));
            }
        }
        throw new InvalidArgumentException('No recurrence found within two years.');
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
