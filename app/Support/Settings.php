<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Settings
{
    private const string AUTOLOAD_CACHE_KEY = 'settings:autoload';

    public static function warm(): void
    {
        // Preload toàn bộ settings autoload=true vào 1 cache key
        Cache::rememberForever(self::AUTOLOAD_CACHE_KEY, function () {
            return Setting::query()
                ->where('autoload', true)
                ->get(['group', 'key', 'type', 'value', 'is_encrypted'])
                ->mapWithKeys(fn ($s) => [
                    "$s->group.$s->key" => [
                        'type' => $s->type,
                        'value' => $s->value,
                        'is_encrypted' => (bool) $s->is_encrypted,
                    ],
                ])
                ->toArray();
        });
    }

    public static function ensure(
        string $group,
        string $key,
        mixed $value,
        string $type = 'string',
        bool $encrypted = false,
        bool $autoload = true,
    ): void {
        $exists = Setting::query()
            ->where('group', $group)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            return;
        }

        self::set($group, $key, $value, $type, $encrypted, $autoload);
    }

    public static function seedDefaults(array $defaults): void
    {
        foreach ($defaults as $group => $items) {
            foreach ($items as $key => $meta) {
                self::ensure(
                    group: $group,
                    key: $key,
                    value: $meta['value'] ?? null,
                    type: $meta['type'] ?? 'string',
                    encrypted: (bool) ($meta['encrypted'] ?? false),
                    autoload: (bool) ($meta['autoload'] ?? true),
                );
            }
        }

        self::warm();
    }

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $compound = "$group.$key";

        // 1) Get cache from an autoload first
        $autoloadMap = Cache::get(self::AUTOLOAD_CACHE_KEY);
        if (is_array($autoloadMap) && array_key_exists($compound, $autoloadMap)) {
            $row = $autoloadMap[$compound];

            $raw = $row['value'];
            if ($row['is_encrypted'] && $raw !== null) {
                $raw = Crypt::decryptString($raw);
            }

            return self::castFromType($raw, $row['type']);
        }

        // 2) Not an autoload, cache by key
        $cacheKey = "settings:$compound";
        $row = Cache::rememberForever($cacheKey, function () use ($group, $key) {
            return Setting::query()
                ->where('group', $group)
                ->where('key', $key)
                ->first(['type', 'value', 'is_encrypted']);
        });

        if (! $row) {
            return $default;
        }

        $raw = $row->is_encrypted && $row->value !== null
            ? Crypt::decryptString($row->value)
            : $row->value;

        return self::castFromType($raw, $row->type);
    }

    public static function set(
        string $group,
        string $key,
        mixed $value,
        string $type = 'string',
        bool $encrypted = false,
        bool $autoload = true
    ): void {
        $raw = self::castToStorage($value, $type);

        if ($encrypted && $raw !== null) {
            $raw = Crypt::encryptString($raw);
        }

        Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'type' => $type,
                'value' => $raw,
                'is_encrypted' => $encrypted,
                'autoload' => $autoload,
            ],
        );

        // Clear caches
        Cache::forget("settings:$group.$key");
        Cache::forget(self::AUTOLOAD_CACHE_KEY);
    }

    private static function castFromType(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $raw,
            'bool', 'boolean' => filter_var($raw, FILTER_VALIDATE_BOOL),
            'json' => json_decode($raw, true) ?? null,
            'float' => (float) $raw,
            default => $raw,
        };
    }

    private static function castToStorage(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
            default => (string) $value,
        };
    }
}
