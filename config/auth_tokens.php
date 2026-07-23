<?php

return [
    'legacy_endpoints' => (bool) env('AUTH_LEGACY_TOKEN_ENDPOINTS', false),
    'access_minutes' => max(1, (int) env('AUTH_ACCESS_TOKEN_MINUTES', 15)),
    'refresh_idle_days' => max(1, (int) env('AUTH_REFRESH_IDLE_DAYS', 30)),
    'refresh_absolute_days' => max(1, (int) env('AUTH_REFRESH_ABSOLUTE_DAYS', 90)),
    'refresh_replay_seconds' => max(10, (int) env('AUTH_REFRESH_REPLAY_SECONDS', 60)),
    'refresh_lock_seconds' => max(10, (int) env('AUTH_REFRESH_LOCK_SECONDS', 30)),
    'device_hash_key' => env('AUTH_DEVICE_HASH_KEY') ?: env('APP_KEY'),
];
