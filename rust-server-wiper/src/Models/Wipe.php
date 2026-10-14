<?php
declare(strict_types=1);
namespace RustServerWiper\Models;
use Illuminate\Database\Eloquent\Model;
final class Wipe extends Model
{
    protected $table = 'ext_rust_server_wiper_wipes';
    protected $guarded = ['id'];
    protected $casts = ['server_id' => 'integer', 'legacy_id' => 'integer', 'payload' => 'array', 'repeat' => 'array', 'enabled' => 'boolean', 'next_run_at' => 'immutable_datetime'];
}
