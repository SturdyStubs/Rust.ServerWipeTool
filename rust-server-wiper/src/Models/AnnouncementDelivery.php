<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

final class AnnouncementDelivery extends UtcModel
{
    protected $table = 'ext_rust_server_wiper_notifications';

    protected $guarded = ['id'];

    protected $hidden = ['delivery_payload'];

    protected $casts = ['server_id' => 'integer', 'run_id' => 'integer', 'expires_at' => 'immutable_datetime', 'delivery_payload' => 'encrypted:array'];
}
