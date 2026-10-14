<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['wipes', 'runs'] as $name) {
            $table = 'ext_rust_server_wiper_'.$name;
            foreach (['created_by', 'updated_by'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $t) use ($column): void {
                        $t->unsignedInteger($column)->nullable();
                    });
                }
            }
        }
        if (! Schema::hasColumn('ext_rust_server_wiper_wipes', 'batch_uuid')) {
            Schema::table('ext_rust_server_wiper_wipes', function (Blueprint $t): void {
                $t->string('batch_uuid', 36)->nullable();
                $t->unique(['server_id', 'batch_uuid']);
            });
        }
        foreach (['not_before' => 'dateTime', 'phase' => 'string', 'backup_uuid' => 'string', 'original_environment' => 'text', 'timeline' => 'json'] as $column => $type) {
            if (! Schema::hasColumn('ext_rust_server_wiper_runs', $column)) {
                Schema::table('ext_rust_server_wiper_runs', function (Blueprint $t) use ($column, $type): void {
                    $t->{$type}($column)->nullable();
                });
            }
        }
        if (! Schema::hasColumn('ext_rust_server_wiper_runs', 'request_uuid')) {
            Schema::table('ext_rust_server_wiper_runs', function (Blueprint $t): void {
                $t->string('request_uuid', 36)->nullable();
                $t->unique(['server_id', 'request_uuid']);
            });
        }
        foreach (['weight', 'position'] as $column) {
            if (! Schema::hasColumn('ext_rust_server_wiper_maps', $column)) {
                Schema::table('ext_rust_server_wiper_maps', function (Blueprint $t) use ($column): void {
                    $t->unsignedInteger($column)->default($column === 'weight' ? 1 : 0);
                });
            }
        }
        if (! Schema::hasTable('ext_rust_server_wiper_presets')) {
            Schema::create('ext_rust_server_wiper_presets', function (Blueprint $t): void {
                $t->id();
                $t->unsignedInteger('server_id');
                $t->string('name', 191);
                $t->json('payload');
                $t->timestamps();
                $t->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_rust_server_wiper_settings')) {
            Schema::create('ext_rust_server_wiper_settings', function (Blueprint $t): void {
                $t->id();
                $t->unsignedInteger('server_id')->unique();
                $t->json('options');
                $t->text('discord_webhook')->nullable();
                $t->text('rcon_password')->nullable();
                $t->timestamps();
                $t->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_rust_server_wiper_maintenance')) {
            Schema::create('ext_rust_server_wiper_maintenance', function (Blueprint $t): void {
                $t->unsignedInteger('server_id')->primary();
                $t->string('operation');
                $t->dateTime('expires_at');
                $t->timestamps();
                $t->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_rust_server_wiper_restores')) {
            Schema::create('ext_rust_server_wiper_restores', function (Blueprint $t): void {
                $t->id();
                $t->unsignedInteger('server_id');
                $t->unsignedBigInteger('run_id');
                $t->unsignedInteger('created_by');
                $t->string('backup_uuid');
                $t->string('request_uuid', 36)->nullable();
                $t->unique(['server_id', 'request_uuid']);
                $t->string('status')->default('queued');
                $t->text('message')->nullable();
                $t->dateTime('finished_at')->nullable();
                $t->timestamps();
                $t->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
                $t->foreign('run_id')->references('id')->on('ext_rust_server_wiper_runs')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_rust_server_wiper_notifications')) {
            Schema::create('ext_rust_server_wiper_notifications', function (Blueprint $t): void {
                $t->id();
                $t->unsignedInteger('server_id');
                $t->string('event_key', 191)->unique();
                $t->string('status');
                $t->text('message')->nullable();
                $t->timestamps();
                $t->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['notifications', 'restores', 'maintenance', 'settings', 'presets'] as $name) {
            Schema::dropIfExists('ext_rust_server_wiper_'.$name);
        }
        Schema::table('ext_rust_server_wiper_runs', function (Blueprint $t): void {
            $t->dropUnique(['server_id', 'request_uuid']);
            $t->dropColumn(['request_uuid', 'not_before', 'phase', 'backup_uuid', 'original_environment', 'timeline', 'created_by', 'updated_by']);
        });
        Schema::table('ext_rust_server_wiper_wipes', function (Blueprint $t): void {
            $t->dropUnique(['server_id', 'batch_uuid']);
            $t->dropColumn(['created_by', 'updated_by', 'batch_uuid']);
        });
        Schema::table('ext_rust_server_wiper_maps', fn (Blueprint $t) => $t->dropColumn(['weight', 'position']));
    }
};
