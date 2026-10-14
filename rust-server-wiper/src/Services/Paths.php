<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use InvalidArgumentException;

final class Paths
{
    public static function normalize(string $path, bool $wildcards = true): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new InvalidArgumentException('Use an absolute server path with forward slashes.');
        }
        $parts = explode('/', substr($path, 1));
        foreach ($parts as $i => $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '?') || str_contains($part, '[') ||
                (str_contains($part, '*') && (! $wildcards || $i !== count($parts) - 1))) {
                throw new InvalidArgumentException('Paths cannot contain traversal or wildcards in directories.');
            }
        }
        if (count($parts) < 2 || end($parts) === '*') {
            throw new InvalidArgumentException('Root entries and bare * deletion patterns are not allowed.');
        }

        return $path;
    }

    public static function patterns(array $payload): array
    {
        $paths = preg_split('/\r\n|\n|\r/', $payload['files'] ?? '') ?: [];
        $paths = array_values(array_filter(array_map('trim', $paths), fn ($p) => $p !== ''));
        if ($payload['blueprints'] ?? false) {
            $root = self::normalize($payload['identity_path'], false);
            $paths = [...$paths, "$root/player.blueprints.*.db", "$root/player.blueprints.*.db-journal", "$root/player.blueprints.*.db-wal", "$root/player.blueprints.*.db-shm"];
        }

        return array_values(array_unique(array_map(self::normalize(...), $paths)));
    }

    /** Resolve before deletion, batch by directory, never traverse a symlink. */
    public static function resolve(array $patterns, callable $list): array
    {
        $directories = [];
        $result = [];
        foreach ($patterns as $pattern) {
            $pattern = self::normalize($pattern);
            $root = dirname($pattern);
            $name = basename($pattern);
            // Check each directory component so an explicit path cannot cross a symlink.
            $parent = '/';
            foreach (explode('/', trim($root, '/')) as $part) {
                $entries = $directories[$parent] ??= $list($parent);
                $entry = null;
                foreach ($entries as $item) {
                    if (($item['name'] ?? '') === $part) {
                        $entry = $item;
                        break;
                    }
                }
                if (! $entry) {
                    continue 2;
                } // Missing directory means nothing to remove.
                if (($entry['symlink'] ?? false) || ($entry['file'] ?? false)) {
                    throw new InvalidArgumentException("Unsafe directory: $parent$part");
                }
                $parent = rtrim($parent, '/').'/'.$part;
            }
            $entries = $directories[$root] ??= $list($root);
            $regex = '~^'.str_replace('\\*', '.*', preg_quote($name, '~')).'$~D';
            foreach ($entries as $entry) {
                $entryName = $entry['name'] ?? '';
                if ($entryName === '' || str_contains($entryName, '/') || str_contains($entryName, '\\') || in_array($entryName, ['.', '..'], true)) {
                    continue;
                }
                if (! preg_match($regex, $entryName)) {
                    continue;
                }
                if ($entry['symlink'] ?? false) {
                    throw new InvalidArgumentException("Refusing to delete symlink: $root/$entryName");
                }
                if (str_contains($name, '*') && ! ($entry['file'] ?? false)) {
                    continue;
                }
                $result[$root][$entryName] = $entryName;
                if (array_sum(array_map('count', $result)) > 5000) {
                    throw new InvalidArgumentException('Deletion exceeds 5,000 entries. Narrow the file patterns.');
                }
            }
        }

        return array_map(array_values(...), $result);
    }
}
