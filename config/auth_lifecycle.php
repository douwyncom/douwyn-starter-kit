<?php

$frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

return [
    'email_verification_minutes' => max(5, (int) env('AUTH_EMAIL_VERIFICATION_MINUTES', 60)),
    'password_reset_minutes' => max(5, (int) env('AUTH_PASSWORD_RESET_MINUTES', 30)),
    'email_change_minutes' => max(5, (int) env('AUTH_EMAIL_CHANGE_MINUTES', 30)),

    'frontend_urls' => [
        'email_verification' => env('AUTH_EMAIL_VERIFICATION_URL', $frontendUrl.'/auth/verify-email'),
        'password_reset' => env('AUTH_PASSWORD_RESET_URL', $frontendUrl.'/auth/reset-password'),
        'email_change' => env('AUTH_EMAIL_CHANGE_URL', $frontendUrl.'/account/confirm-email-change'),
    ],
];
