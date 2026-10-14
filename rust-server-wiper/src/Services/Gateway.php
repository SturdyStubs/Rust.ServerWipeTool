<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

interface Gateway
{
    public function assertReady(int $serverId): void;

    public function environment(int $serverId): array;

    public function validateEnvironment(int $serverId, array $changes): void;

    public function updateEnvironment(int $serverId, array $changes): void;

    public function list(int $serverId, string $directory): array;

    public function state(int $serverId): string;

    public function power(int $serverId, string $action): void;

    public function commands(int $serverId, string $command): void;

    public function readOptional(int $serverId, string $path): ?string;

    public function write(int $serverId, string $path, string $content): void;

    public function delete(int $serverId, string $root, array $names): void;

    public function sync(int $serverId): void;

    public function wait(): void;

    public function verifyMap(string $url): void;

    public function playerCount(int $serverId): int;

    public function backupStart(int $serverId, string $name): string;

    public function backupStatus(int $serverId, string $uuid): ?bool;

    public function restoreStart(int $serverId, string $uuid, int $userId): void;

    public function restoreStatus(int $serverId, string $uuid): ?bool;
}
