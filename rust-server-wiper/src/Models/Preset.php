<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

final class Preset extends UtcModel
{
    protected $table = 'ext_rust_server_wiper_presets';

    protected $guarded = ['id'];

    protected $casts = ['server_id' => 'integer', 'payload' => 'array'];
}
