<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Vai trò',
    'columns' => [
        'name' => 'Vai trò',
        'guard' => 'Guard',
        'permissions' => 'Quyền hạn',
    ],
    'fields' => [
        'section_role' => 'Chi tiết vai trò',
        'section_role_helper' => 'Thông tin cơ bản được sử dụng để xác định và ủy quyền cho vai trò này.',
        'role_name' => 'Tên vai trò',
        'role_placeholder' => 'vd: Admin, Biên tập viên, Quản lý tài chính',
        'role_helper' => 'Sử dụng một cái tên dễ hiểu. Bạn có thể thay đổi nó sau.',
        'guard_name' => 'Guard',
        'guard_name_helper' => 'Thường là "web". Chỉ thay đổi nếu bạn biết mình đang làm gì.',
        'section_permissions' => 'Quyền hạn',
        'section_permissions_helper' => 'Gán quyền cho vai trò này.',
        'new_permission' => 'Quyền mới',
        'create_permission' => 'Tạo quyền',
        'permission_name' => 'Tên quyền',
        'permission_placeholder' => 'vd: users.create hoặc Tạo người dùng',
        'permission_helper' => 'Mẹo: sử dụng tìm kiếm để nhanh chóng tìm thấy quyền.',
    ],
];
