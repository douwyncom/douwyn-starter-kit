<?php

return [
    'version' => trim((string) env('API_VERSION', '1.0.0')) ?: '1.0.0',
    'rate_limit_per_minute' => max(1, (int) env('API_RATE_LIMIT_PER_MINUTE', 60)),

    'lifecycle' => [
        'deprecated' => filter_var(env('API_DEPRECATED', false), FILTER_VALIDATE_BOOL),
        'deprecation_at' => env('API_DEPRECATION_AT'),
        'sunset_at' => env('API_SUNSET_AT'),
        'deprecation_documentation_url' => env('API_DEPRECATION_DOCUMENTATION_URL'),
    ],
];
