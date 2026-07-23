<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Roles',
    'columns' => [
        'name' => 'Role',
        'guard' => 'Guard',
        'permissions' => 'Permissions',
    ],
    'fields' => [
        'section_role' => 'Role details',
        'section_role_helper' => 'Basic information used to identify and authorize this role.',
        'role_name' => 'Role name',
        'role_placeholder' => 'e.g. Admin, Editor, Finance Manager',
        'role_helper' => 'Use a human-readable name. You can change it later.',
        'guard_name' => 'Guard',
        'guard_name_helper' => 'Usually "web". Change only if you know what you are doing.',
        'section_permissions' => 'Permissions',
        'section_permissions_helper' => 'Assign permissions to this role.',
        'new_permission' => 'New permission',
        'create_permission' => 'Create permission',
        'permission_name' => 'Permission name',
        'permission_placeholder' => 'e.g. users.create or Users Create',
        'permission_helper' => 'Tip: use search to quickly find permissions.',
    ],
];
