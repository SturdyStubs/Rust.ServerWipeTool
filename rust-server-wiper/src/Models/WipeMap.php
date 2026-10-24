<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

final class WipeMap extends UtcModel
{
    protected $table = 'ext_rust_server_wiper_maps';

    protected $guarded = ['id'];

    protected $casts = ['server_id' => 'integer', 'legacy_id' => 'integer', 'weight' => 'integer', 'position' => 'integer'];
}
