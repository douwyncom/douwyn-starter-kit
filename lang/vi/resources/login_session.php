<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Phiên đăng nhập',
    'resource_label' => 'phiên đăng nhập',
    'plural_resource_label' => 'Phiên đăng nhập',
    'columns' => [
        'user' => 'Người dùng',
        'device' => 'Thiết bị',
        'ip_address' => 'Địa chỉ IP',
        'last_active' => 'Hoạt động gần nhất',
        'current' => 'Hiện tại',
        'status' => 'Trạng thái',
        'signed_in_at' => 'Đăng nhập lúc',
        'revoked_at' => 'Thu hồi lúc',
    ],
    'filters' => [
        'user' => 'Người dùng',
        'status' => 'Trạng thái',
    ],
    'statuses' => [
        'active' => 'Hoạt động',
        'expired' => 'Hết hạn',
        'revoked' => 'Đã thu hồi',
    ],
    'devices' => [
        'unknown_browser' => 'Trình duyệt không xác định',
        'unknown_device' => 'Thiết bị không xác định',
    ],
    'actions' => [
        'view_user' => 'Quản lý người dùng',
        'revoke' => 'Thu hồi phiên',
        'revoke_selected' => 'Thu hồi các phiên đã chọn',
        'manage_all' => 'Quản lý tất cả phiên',
    ],
    'messages' => [
        'revoked' => 'Đã thu hồi phiên đăng nhập.',
        'selected_revoked' => '{0} Không có phiên nào được thu hồi.|{1} Đã thu hồi một phiên đăng nhập.|[2,*] Đã thu hồi :count phiên đăng nhập.',
    ],
];
