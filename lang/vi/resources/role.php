<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Vai trò',
    'resource_label' => 'vai trò',
    'plural_resource_label' => 'Vai trò',
    'columns' => [
        'name' => 'Vai trò',
        'guard' => 'Cơ chế xác thực',
        'permissions' => 'Quyền hạn',
    ],
    'fields' => [
        'section_role' => 'Chi tiết vai trò',
        'section_role_helper' => 'Thông tin cơ bản được sử dụng để xác định và ủy quyền cho vai trò này.',
        'role_name' => 'Tên vai trò',
        'role_placeholder' => 'vd: Quản trị viên, Biên tập viên, Quản lý tài chính',
        'role_helper' => 'Sử dụng một cái tên dễ hiểu. Bạn có thể thay đổi nó sau.',
        'guard_name' => 'Cơ chế xác thực',
        'guard_name_helper' => 'Thường sử dụng giá trị "web". Chỉ thay đổi khi bạn hiểu rõ cơ chế xác thực.',
        'section_permissions' => 'Quyền hạn',
        'section_permissions_helper' => 'Gán quyền cho vai trò này.',
        'new_permission' => 'Quyền mới',
        'create_permission' => 'Tạo quyền',
        'create_action' => 'Tạo',
        'permission_name' => 'Tên quyền',
        'permission_placeholder' => 'vd: users.create hoặc Tạo người dùng',
        'permission_helper' => 'Mẹo: sử dụng tìm kiếm để nhanh chóng tìm thấy quyền.',
    ],
    'messages' => [
        'system_role_immutable' => 'Không thể thay đổi tên hoặc cơ chế xác thực của vai trò hệ thống.',
        'super_admin_requires_panel_access' => 'Vai trò quản trị viên cấp cao phải giữ quyền truy cập bảng quản trị.',
        'invalid_permission_name' => 'Tên quyền không hợp lệ.',
    ],
];
