<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ext_rust_server_wiper_wipes')) {
            Schema::create('ext_rust_server_wiper_wipes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('server_id');
                $table->string('label');
                $table->string('timezone', 64)->default('UTC');
                $table->json('payload');
                $table->json('repeat');
                $table->dateTime('next_run_at')->nullable()->index();
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->timestamps();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_rust_server_wiper_maps')) {
            Schema::create('ext_rust_server_wiper_maps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('server_id');
                $table->string('name');
                $table->text('url');
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->timestamps();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_rust_server_wiper_runs')) {
            Schema::create('ext_rust_server_wiper_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('server_id');
                $table->unsignedBigInteger('wipe_id')->nullable();
                $table->string('label');
                $table->json('payload');
                $table->string('timezone', 64);
                $table->dateTime('due_at');
                $table->string('status', 24)->default('queued')->index();
                $table->text('message')->nullable();
                $table->json('result')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->timestamps();
                $table->unique(['wipe_id', 'due_at']);
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
                $table->foreign('wipe_id')->references('id')->on('ext_rust_server_wiper_wipes')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_rust_server_wiper_runs');
        Schema::dropIfExists('ext_rust_server_wiper_maps');
        Schema::dropIfExists('ext_rust_server_wiper_wipes');
    }
};
