<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

final class Restoration extends UtcModel
{
    protected $table = 'ext_rust_server_wiper_restores';

    protected $guarded = ['id'];

    protected $casts = ['server_id' => 'integer', 'run_id' => 'integer', 'created_by' => 'integer', 'finished_at' => 'immutable_datetime'];
}
