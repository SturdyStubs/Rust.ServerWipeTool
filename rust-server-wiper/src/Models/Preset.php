<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

use Illuminate\Database\Eloquent\Model;

final class Preset extends Model
{
    protected $table = 'ext_rust_server_wiper_presets';

    protected $guarded = ['id'];

    protected $casts = ['server_id' => 'integer', 'payload' => 'array'];
}
