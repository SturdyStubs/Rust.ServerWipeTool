<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RustServerWiper\Models\Settings;
use RustServerWiper\Models\Wipe;

final class AnnouncementConfig
{
    public static function current(Settings $settings): array
    {
        if ($settings->announcement_config !== null) {
            return $settings->announcement_config;
        }
        $empty = ['revision' => 0, 'enabled' => true, 'webhooks' => [], 'presets' => [], 'rules' => []];
        if (! $settings->discord_webhook) {
            return $empty;
        }
        // Stable IDs let blank secret inputs preserve legacy URLs on the first save.
        $id = fn ($n) => sprintf('00000000-0000-4000-8000-%012d', $n);
        $options = $settings->options ?? [];
        $events = array_values(array_unique(array_map(fn ($event) => match ($event) {
            'upcoming' => 'before', 'completed', 'ready' => 'wiped', default => $event
        }, $options['discord_events'] ?? ['upcoming', 'completed', 'ready', 'failed'])));

        return [...$empty,
            'webhooks' => [['id' => $id(1), 'name' => 'Existing Discord channel', 'url' => $settings->discord_webhook, 'enabled' => true]],
            'presets' => [['id' => $id(2), 'name' => 'Existing notification message', 'content' => $options['discord_template'] ?? '{label}: {event}. {message}', 'embeds' => [], 'role_ids' => empty($options['discord_role']) ? [] : [$options['discord_role']], 'mention_everyone' => false]],
            'rules' => array_map(fn ($event, $index) => ['id' => $id(10 + $index), 'name' => 'Existing '.$event.' announcement', 'enabled' => true, 'event' => $event, 'minutes' => $event === 'before' ? ($options['discord_minutes'] ?? 30) : null, 'preset_id' => $id(2), 'webhook_ids' => [$id(1)], 'wipe_id' => null], $events, array_keys($events)),
        ];
    }

    public static function publicData(Settings $settings): array
    {
        $config = self::current($settings);
        $config['webhooks'] = array_map(fn ($hook) => [...$hook, 'url' => '', 'configured' => ! empty($hook['url'])], $config['webhooks']);

        return $config;
    }

    public static function preset(array $preset): array
    {
        $validated = Validator::make($preset, [
            'id' => 'required|uuid', 'name' => 'required|string|max:191',
            'content' => 'present|nullable|string|max:2000', 'embeds' => 'present|array|max:10',
            'role_ids' => 'present|array|max:20', 'role_ids.*' => ['string', 'distinct', 'regex:/^[0-9]{1,20}$/D'], 'mention_everyone' => 'required|boolean',
        ])->validate();
        $message = AnnouncementMessage::validate(['content' => $validated['content'], 'embeds' => $validated['embeds']]);

        return [...$validated, ...$message];
    }

    public static function validate(array $input, Settings $settings): array
    {
        $data = Validator::make($input, [
            'revision' => 'required|integer|min:0', 'enabled' => 'required|boolean',
            'webhooks' => 'present|array|max:100', 'webhooks.*' => 'array:id,name,url,enabled,configured',
            'webhooks.*.id' => 'required|uuid|distinct', 'webhooks.*.name' => 'required|string|max:191', 'webhooks.*.url' => 'nullable|string|max:250', 'webhooks.*.enabled' => 'required|boolean',
            'presets' => 'present|array|max:200', 'presets.*' => 'array:id,name,content,embeds,role_ids,mention_everyone', 'presets.*.id' => 'required|uuid|distinct',
            'rules' => 'present|array|max:500', 'rules.*' => 'array:id,name,enabled,event,minutes,preset_id,webhook_ids,wipe_id',
            'rules.*.id' => 'required|uuid|distinct', 'rules.*.name' => 'required|string|max:191', 'rules.*.enabled' => 'required|boolean',
            'rules.*.event' => 'required|in:before,wiping,wiped,failed', 'rules.*.minutes' => 'nullable|integer|min:1|max:10080',
            'rules.*.preset_id' => 'required|uuid', 'rules.*.webhook_ids' => 'required|array|min:1|max:100', 'rules.*.webhook_ids.*' => 'required|uuid', 'rules.*.wipe_id' => 'nullable|integer',
        ])->validate();
        abort_if(strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 2 * 1024 * 1024, 422, 'Announcement configuration must fit within 2 MB.');
        $current = self::current($settings);
        abort_unless((int) $data['revision'] === $current['revision'], 409, 'Announcements changed in another session. Reload before saving.');
        $saved = array_column($current['webhooks'], null, 'id');
        foreach ($data['webhooks'] as $index => &$hook) {
            $hook['url'] = trim($hook['url'] ?? '') ?: ($saved[$hook['id']]['url'] ?? '');
            if (! Notifications::validWebhook($hook['url'])) {
                throw ValidationException::withMessages(['webhooks.'.$index.'.url' => 'Enter a Discord webhook URL. Leave an existing URL blank to keep it.']);
            }
            $hook['enabled'] = (bool) $hook['enabled'];
            unset($hook['configured']);
        }
        unset($hook);
        foreach ($data['presets'] as $index => &$preset) {
            try {
                $preset = self::preset($preset);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['presets.'.$index => 'Message preset '.($index + 1).': '.implode(' ', $e->validator->errors()->all())]);
            }
        }
        unset($preset);
        $hooks = array_column($data['webhooks'], 'id');
        $presets = array_column($data['presets'], 'id');
        foreach ($data['rules'] as $index => &$rule) {
            if (! in_array($rule['preset_id'], $presets, true) || array_diff($rule['webhook_ids'], $hooks)) {
                throw ValidationException::withMessages(['rules.'.$index => 'Choose a saved message preset and valid destinations for every rule.']);
            }
            if ($rule['event'] === 'before' && ! isset($rule['minutes'])) {
                throw ValidationException::withMessages(['rules.'.$index.'.minutes' => 'Choose how many minutes before the wipe to announce.']);
            }
            if (! empty($rule['wipe_id']) && ! Wipe::query()->where('server_id', $settings->server_id)->whereKey($rule['wipe_id'])->exists()) {
                throw ValidationException::withMessages(['rules.'.$index.'.wipe_id' => 'Choose a schedule on this server.']);
            }
            $rule['webhook_ids'] = array_values(array_unique($rule['webhook_ids']));
            $rule['minutes'] = $rule['event'] === 'before' ? (int) $rule['minutes'] : null;
            $rule['wipe_id'] = isset($rule['wipe_id']) ? (int) $rule['wipe_id'] : null;
            $rule['enabled'] = (bool) $rule['enabled'];
        }
        unset($rule);
        $data['revision'] = (int) $data['revision'] + 1;
        $data['enabled'] = (bool) $data['enabled'];

        return $data;
    }
}
