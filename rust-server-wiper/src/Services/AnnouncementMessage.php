<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AnnouncementMessage
{
    /** Accept Discord's rich embed fields, with the same bounds at save and delivery time. */
    public static function validate(array $message): array
    {
        $data = Validator::make($message, [
            'content' => 'present|nullable|string|max:2000', 'embeds' => 'present|array|max:10',
            'embeds.*' => 'array:title,description,url,color,footer,image,thumbnail,author,fields,timestamp',
            'embeds.*.title' => 'nullable|string|max:256', 'embeds.*.description' => 'nullable|string|max:4096',
            'embeds.*.url' => 'nullable|url:http,https|max:2000', 'embeds.*.color' => 'sometimes|integer|min:0|max:16777215',
            'embeds.*.footer' => 'sometimes|array:text,icon_url', 'embeds.*.footer.text' => 'required_with:embeds.*.footer|string|max:2048', 'embeds.*.footer.icon_url' => 'nullable|url:http,https|max:2000',
            'embeds.*.image' => 'sometimes|array:url', 'embeds.*.image.url' => 'required_with:embeds.*.image|url:http,https|max:2000',
            'embeds.*.thumbnail' => 'sometimes|array:url', 'embeds.*.thumbnail.url' => 'required_with:embeds.*.thumbnail|url:http,https|max:2000',
            'embeds.*.author' => 'sometimes|array:name,url,icon_url', 'embeds.*.author.name' => 'required_with:embeds.*.author|string|max:256',
            'embeds.*.author.url' => 'nullable|url:http,https|max:2000', 'embeds.*.author.icon_url' => 'nullable|url:http,https|max:2000',
            'embeds.*.fields' => 'sometimes|array|max:25', 'embeds.*.fields.*' => 'array:name,value,inline',
            'embeds.*.fields.*.name' => 'required|string|max:256', 'embeds.*.fields.*.value' => 'required|string|max:1024', 'embeds.*.fields.*.inline' => 'sometimes|boolean',
            'embeds.*.timestamp' => ['sometimes', 'string', 'regex:/^(?:\{iso_time\}|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))$/D'],
        ])->validate();
        $total = 0;
        $embeds = [];
        $visible = trim($data['content'] ?? '') !== '';
        foreach ($data['embeds'] as $embed) {
            foreach ([$embed['title'] ?? '', $embed['description'] ?? '', $embed['footer']['text'] ?? '', $embed['author']['name'] ?? ''] as $text) {
                $total += mb_strlen($text);
            }
            foreach ($embed['fields'] ?? [] as $field) {
                $total += mb_strlen($field['name']) + mb_strlen($field['value']);
            }
            $hasContent = ! empty($embed['title']) || ! empty($embed['description']) || ! empty($embed['fields']) || ! empty($embed['image']['url']) || ! empty($embed['thumbnail']['url']) || ! empty($embed['author']['name']) || ! empty($embed['footer']['text']);
            if ($hasContent) {
                $embeds[] = $embed;
            }
            $visible = $visible || $hasContent;
        }
        if (! $visible) {
            throw ValidationException::withMessages(['message' => 'Add message content or visible embed content. Both are optional individually.']);
        }
        if ($total > 6000) {
            throw ValidationException::withMessages(['embeds' => 'All embed text combined must fit within 6,000 characters.']);
        }
        $data['embeds'] = $embeds;

        return $data;
    }

    public static function render(array $preset, array $values): array
    {
        $replace = [];
        foreach (['label', 'server', 'event', 'message', 'time', 'epoch', 'timestamp', 'relative', 'minutes', 'iso_time'] as $name) {
            $replace['{'.$name.'}'] = (string) ($values[$name] ?? '');
        }
        $expand = function (mixed $value) use (&$expand, $replace): mixed {
            if (is_array($value)) {
                return array_map($expand, $value);
            }

            return is_string($value) ? strtr($value, $replace) : $value;
        };
        $content = $expand($preset['content'] ?? '');
        $roles = $preset['role_ids'] ?? [];
        if ($roles) {
            $content = implode(' ', array_map(fn ($id) => '<@&'.$id.'>', $roles)).($content !== '' ? ' '.$content : '');
        }
        $message = self::validate(['content' => $content, 'embeds' => $expand($preset['embeds'] ?? [])]);
        $message['allowed_mentions'] = ['parse' => ($preset['mention_everyone'] ?? false) ? ['everyone'] : [], 'roles' => $roles, 'users' => []];
        if (empty($message['content'])) {
            unset($message['content']);
        }
        if (empty($message['embeds'])) {
            unset($message['embeds']);
        }

        return $message;
    }
}
