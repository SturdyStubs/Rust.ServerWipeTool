<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use RuntimeException;

final class MapSelector
{
    public static function choose(array $maps, string $mode, array $recent, string $current, int $exclude): array
    {
        $unique = [];
        foreach ($maps as $i => $map) {
            $map = is_string($map) ? ['url' => $map, 'weight' => 1, 'position' => $i] : $map;
            $unique[$map['url']] ??= $map;
        }
        $all = array_values($unique);
        if (! $all) {
            throw new RuntimeException('Map rotation is enabled, but the map list is empty.');
        }
        usort($all, fn ($a, $b) => [$a['position'] ?? 0, $a['id'] ?? 0, $a['url']] <=> [$b['position'] ?? 0, $b['id'] ?? 0, $b['url']]);
        $blocked = array_unique([$current, ...array_slice($recent, 0, $exclude)]);
        $choices = array_values(array_filter($all, fn ($m) => ! in_array($m['url'], $blocked, true)));
        $fallback = false;
        if (! $choices) {
            $fallback = true;
            $choices = array_values(array_filter($all, fn ($m) => $m['url'] !== $current)) ?: $all;
        }
        if ($mode === 'ordered') {
            $start = 0;
            foreach ($all as $i => $map) {
                if ($map['url'] === ($recent[0] ?? $current)) {
                    $start = $i + 1;
                }
            }
            for ($i = 0; $i < count($all); $i++) {
                $map = $all[($start + $i) % count($all)];
                if (in_array($map['url'], array_column($choices, 'url'), true)) {
                    return ['url' => $map['url'], 'fallback' => $fallback];
                }
            }
        }
        $weights = array_map(fn ($m) => $mode === 'weighted' ? max(1, min(1000, (int) ($m['weight'] ?? 1))) : 1, $choices);
        $pick = random_int(1, array_sum($weights));
        foreach ($choices as $i => $map) {
            $pick -= $weights[$i];
            if ($pick <= 0) {
                return ['url' => $map['url'], 'fallback' => $fallback];
            }
        }
        throw new RuntimeException('Map selection failed.');
    }
}
