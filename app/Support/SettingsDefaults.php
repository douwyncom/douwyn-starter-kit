<?php

namespace App\Support;

class SettingsDefaults
{
    /**
     * Return a flat list of settings defaults.
     * You can also extend this later (mail, storage, media, etc.).
     */
    public static function all(): array
    {
        return [
            // group => [ key => [value, type, encrypted, autoload] ]
            'general' => [
                'site_name' => [
                    'value' => config('app.name'),
                    'type' => 'string',
                    'encrypted' => false,
                    'autoload' => true,
                ],
                'site_description' => [
                    'value' => null,
                    'type' => 'string',
                    'encrypted' => false,
                    'autoload' => true,
                ],
                'timezone' => [
                    'value' => config('app.timezone'),
                    'type' => 'string',
                    'encrypted' => false,
                    'autoload' => true,
                ],
                'locale' => [
                    'value' => config('app.locale'),
                    'type' => 'string',
                    'encrypted' => false,
                    'autoload' => true,
                ],
            ],
            'media' => [
                'convert_to_avif' => [
                    'value' => false,
                    'type' => 'boolean',
                    'encrypted' => false,
                    'autoload' => true,
                ],
                'convert_to_webp' => [
                    'value' => false,
                    'type' => 'boolean',
                    'encrypted' => false,
                    'autoload' => true,
                ],
            ],
        ];
    }
}
