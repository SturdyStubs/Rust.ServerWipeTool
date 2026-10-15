<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ext_rust_server_wiper_imports')) {
            Schema::create('ext_rust_server_wiper_imports', function (Blueprint $t): void {
                $t->id();
                $t->unsignedInteger('server_id');
                $t->uuid('request_uuid');
                $t->string('content_hash', 64);
                $t->json('summary');
                $t->timestamps();
                $t->unique(['server_id', 'request_uuid']);
                $t->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_rust_server_wiper_imports');
    }
};
