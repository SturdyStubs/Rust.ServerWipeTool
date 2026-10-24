<?php

declare(strict_types=1);

namespace RustServerWiper\Models;

final class Settings extends UtcModel
{
    protected $table = 'ext_rust_server_wiper_settings';

    protected $guarded = ['id'];

    protected $hidden = ['discord_webhook', 'rcon_password', 'announcement_config'];

    protected $casts = ['server_id' => 'integer', 'options' => 'array', 'discord_webhook' => 'encrypted', 'rcon_password' => 'encrypted', 'announcement_config' => 'encrypted:array'];

    public static function forServer(int $id): self
    {
        return self::query()->firstOrNew(['server_id' => $id], ['options' => []]);
    }

    public function publicData(): array
    {
        return ['options' => $this->options ?? [], 'discord_configured' => $this->discord_webhook !== null && $this->discord_webhook !== '', 'rcon_configured' => $this->rcon_password !== null && $this->rcon_password !== ''];
    }
}
