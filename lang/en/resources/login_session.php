<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Login sessions',
    'resource_label' => 'login session',
    'plural_resource_label' => 'Login sessions',
    'columns' => [
        'user' => 'User',
        'device' => 'Device',
        'ip_address' => 'IP address',
        'last_active' => 'Last active',
        'current' => 'Current',
        'status' => 'Status',
        'signed_in_at' => 'Signed in at',
        'revoked_at' => 'Revoked at',
    ],
    'filters' => [
        'user' => 'User',
        'status' => 'Status',
    ],
    'statuses' => [
        'active' => 'Active',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ],
    'devices' => [
        'unknown_browser' => 'Unknown browser',
        'unknown_device' => 'Unknown device',
    ],
    'actions' => [
        'view_user' => 'Manage user',
        'revoke' => 'Revoke session',
        'revoke_selected' => 'Revoke selected sessions',
        'manage_all' => 'Manage all sessions',
    ],
    'messages' => [
        'revoked' => 'Login session revoked.',
        'selected_revoked' => '{0} No session was revoked.|{1} One login session was revoked.|[2,*] :count login sessions were revoked.',
    ],
];
