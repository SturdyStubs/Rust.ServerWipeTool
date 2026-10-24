<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/** The extension's database timestamps are UTC, regardless of APP_TIMEZONE. */
abstract class UtcModel extends Model
{
    protected function asDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            return Date::instance($value)->utc();
        }
        if (is_numeric($value)) {
            return Date::createFromTimestampUTC($value);
        }

        return Date::parse($value, 'UTC')->utc();
    }
}
