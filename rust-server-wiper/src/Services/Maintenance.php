<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Maintenance
{
    private static array $internal = [];

    public static function status(int $id): ?object
    {
        return DB::table('ext_rust_server_wiper_maintenance')->where('server_id', $id)->where('expires_at', '>', now('UTC'))->first();
    }

    public static function guard(int $id): void
    {
        abort_if(empty(self::$internal[$id]) && self::status($id), 409, 'A Rust wipe or restore is in maintenance. Wait for it to finish.');
    }

    public static function acquire(int $id, string $operation): void
    {
        DB::transaction(function () use ($id, $operation): void {
            DB::table('servers')->where('id', $id)->lockForUpdate()->first();
            if (self::status($id)) {
                throw new RuntimeException('This server is already in wipe maintenance.');
            }
            DB::table('ext_rust_server_wiper_maintenance')->updateOrInsert(['server_id' => $id], ['operation' => $operation, 'expires_at' => now('UTC')->addMinutes(25), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        });
    }

    public static function release(int $id, string $operation): void
    {
        DB::table('ext_rust_server_wiper_maintenance')->where('server_id', $id)->where('operation', $operation)->delete();
    }

    public static function internal(int $id, callable $fn): mixed
    {
        self::$internal[$id] = (self::$internal[$id] ?? 0) + 1;
        try {
            return $fn();
        } finally {
            if (--self::$internal[$id] === 0) {
                unset(self::$internal[$id]);
            }
        }
    }
}
