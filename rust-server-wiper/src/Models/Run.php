<?php
declare(strict_types=1);
namespace RustServerWiper\Models;
use Illuminate\Database\Eloquent\Model;
final class Run extends Model
{
    protected $table = 'ext_rust_server_wiper_runs';
    protected $guarded = ['id'];
    protected $casts = ['server_id' => 'integer', 'wipe_id' => 'integer', 'payload' => 'array', 'result' => 'array', 'due_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
}
