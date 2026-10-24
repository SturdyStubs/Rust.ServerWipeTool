<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

final class Wipe extends UtcModel
{
    protected $table = 'ext_rust_server_wiper_wipes';

    protected $guarded = ['id'];

    protected $casts = ['server_id' => 'integer', 'legacy_id' => 'integer', 'payload' => 'array', 'repeat' => 'array', 'enabled' => 'boolean', 'next_run_at' => 'immutable_datetime'];
}
