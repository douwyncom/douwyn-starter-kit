<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Users',
    'navigation_group' => 'User management',
    'resource_label' => 'user',
    'plural_resource_label' => 'Users',
    'sections' => [
        'account' => 'Account',
        'account_helper' => 'Login credentials and email verification.',
        'access' => 'Access',
        'access_helper' => 'Roles and account status.',
        'profile' => 'Profile',
        'profile_helper' => 'Personal information and preferences.',
    ],
    'fields' => [
        'email' => 'Email',
        'password' => 'Password',
        'password_helper' => 'Leave blank when editing to keep the current password.',
        'password_confirmation' => 'Confirm password',
        'email_verified_at' => 'Email verified at',
        'roles' => 'Roles',
        'is_inactive' => 'Inactive account',
        'is_inactive_helper' => 'Inactive users cannot sign in. Their sessions are revoked.',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'phone' => 'Phone',
        'locale' => 'Language',
        'timezone' => 'Timezone',
        'timezone_helper' => 'Choose an IANA timezone. Search by region or city.',
    ],
    'columns' => [
        'name' => 'Name',
        'active' => 'Active',
        'two_factor' => '2FA',
        'sessions' => 'Sessions',
        'api_tokens' => 'API tokens',
    ],
    'actions' => [
        'activate' => 'Activate',
        'deactivate' => 'Deactivate',
        'manage_sessions' => 'Manage login sessions',
        'revoke_sessions' => 'Revoke browser sessions',
    ],
    'messages' => [
        'status_updated' => 'Account status updated.',
        'sessions_revoked' => 'Browser login sessions have been revoked.',
        'cannot_deactivate_self' => 'You cannot deactivate your own account.',
        'last_super_admin' => 'The final Super Admin cannot be removed, deactivated, or deleted.',
        'cannot_assign_roles' => 'You are not authorized to assign roles.',
        'only_super_admin_assign_system_roles' => 'Only a super administrator can assign administrative roles.',
    ],
];
