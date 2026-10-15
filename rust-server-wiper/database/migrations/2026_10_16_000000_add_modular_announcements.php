<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ext_rust_server_wiper_settings', 'announcement_config')) {
            Schema::table('ext_rust_server_wiper_settings', fn (Blueprint $t) => $t->longText('announcement_config')->nullable());
        }
        foreach (['delivery_payload' => 'longText', 'destination_id' => 'string', 'rule_id' => 'string', 'preset_name' => 'string', 'destination_name' => 'string', 'event' => 'string', 'run_id' => 'unsignedBigInteger', 'expires_at' => 'dateTime'] as $column => $type) {
            if (! Schema::hasColumn('ext_rust_server_wiper_notifications', $column)) {
                Schema::table('ext_rust_server_wiper_notifications', fn (Blueprint $t) => $t->{$type}($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        Schema::table('ext_rust_server_wiper_settings', fn (Blueprint $t) => $t->dropColumn('announcement_config'));
        Schema::table('ext_rust_server_wiper_notifications', fn (Blueprint $t) => $t->dropColumn(['delivery_payload', 'destination_id', 'rule_id', 'preset_name', 'destination_name', 'event', 'run_id', 'expires_at']));
    }
};
