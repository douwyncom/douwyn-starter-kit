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
        'preheader' => 'Your one-time verification code is valid for :minutes minutes.',
        'eyebrow' => 'Account security',
        'title' => 'Verify your identity.',
        'code_label' => 'Verification code',
        'expires' => 'Valid for :minutes minutes',
        'one_time' => 'This code can be used once. Enter it on the verification screen you have open.',
        'security_title' => 'Keep this code to yourself.',
        'security_body' => 'Do not share this code with anyone, including someone claiming to be support. We will never ask you to send your code by email, phone, or message.',
        'not_requested' => 'If you did not make this request, ignore this email. The code will expire automatically.',
        'help' => 'Need help?',
        'automated' => 'This automated email helps protect your account.',
        'contexts' => [
            'login' => 'Confirm sign-in',
            'setup' => 'Set up two-factor authentication',
            'security' => 'Confirm a security action',
            'verification' => 'Verify your account',
        ],
        'intros' => [
            'login' => 'We received a request to sign in to your account. Use the code below to continue.',
            'setup' => 'You are setting up email two-factor authentication. Use the code below to confirm this security method.',
            'security' => 'A security action on your account needs confirmation. Use the code below to continue your current request.',
            'verification' => 'Use the code below to complete your account verification request.',
        ],
    ],
];
