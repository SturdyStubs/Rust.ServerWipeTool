<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

use Illuminate\Database\Eloquent\Model;

final class AnnouncementDelivery extends Model
{
    protected $table = 'ext_rust_server_wiper_notifications';

    protected $guarded = ['id'];

    protected $hidden = ['delivery_payload'];

    protected $casts = ['server_id' => 'integer', 'run_id' => 'integer', 'expires_at' => 'immutable_datetime', 'delivery_payload' => 'encrypted:array'];
}
