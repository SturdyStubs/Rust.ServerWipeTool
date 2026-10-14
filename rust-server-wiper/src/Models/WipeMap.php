<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

use Illuminate\Database\Eloquent\Model;

final class WipeMap extends Model
{
    protected $table = 'ext_rust_server_wiper_maps';

    protected $guarded = ['id'];

    protected $casts = ['server_id' => 'integer', 'legacy_id' => 'integer', 'weight' => 'integer', 'position' => 'integer'];
}
