<?php

declare(strict_types=1);

return [
    'verify_email' => [
        'subject' => 'Verify your email address',
        'intro' => 'Confirm that this email address belongs to your account.',
        'action' => 'Verify email address',
        'outro' => 'If you did not create this account, you can ignore this email.',
    ],
    'reset_password' => [
        'subject' => 'Reset your password',
        'intro' => 'We received a request to reset your account password.',
        'action' => 'Reset password',
        'outro' => 'If you did not request a password reset, you can ignore this email.',
    ],
    'confirm_email_change' => [
        'subject' => 'Confirm your new email address',
        'intro' => 'Confirm this address to finish changing your account email.',
        'action' => 'Confirm email change',
        'outro' => 'If you did not request this change, secure your account immediately.',
    ],
    'two_factor' => [
        'subject' => 'Your verification code',
        'body' => "Your verification code is: :code\n\nThis code expires in :minutes minutes.",
    ],
];
