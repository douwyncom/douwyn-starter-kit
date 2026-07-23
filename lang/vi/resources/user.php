<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Người dùng',
    'navigation_group' => 'Quản lý người dùng',
    'sections' => [
        'account' => 'Tài khoản',
        'account_helper' => 'Thông tin đăng nhập và xác minh email.',
        'access' => 'Truy cập',
        'access_helper' => 'Vai trò và trạng thái tài khoản.',
        'profile' => 'Hồ sơ',
        'profile_helper' => 'Thông tin cá nhân và tùy chọn hiển thị.',
    ],
    'fields' => [
        'email' => 'Email',
        'password' => 'Mật khẩu',
        'password_helper' => 'Để trống khi chỉnh sửa nếu muốn giữ mật khẩu hiện tại.',
        'password_confirmation' => 'Xác nhận mật khẩu',
        'email_verified_at' => 'Thời gian xác minh email',
        'roles' => 'Vai trò',
        'is_inactive' => 'Tài khoản không hoạt động',
        'is_inactive_helper' => 'Người dùng bị vô hiệu hóa không thể đăng nhập và các phiên sẽ bị thu hồi.',
        'first_name' => 'Tên',
        'last_name' => 'Họ',
        'phone' => 'Điện thoại',
        'locale' => 'Ngôn ngữ',
        'timezone' => 'Múi giờ',
        'timezone_helper' => 'Chọn múi giờ IANA. Có thể tìm theo khu vực hoặc thành phố.',
    ],
    'columns' => [
        'name' => 'Họ tên',
        'active' => 'Hoạt động',
        'two_factor' => '2FA',
        'sessions' => 'Phiên',
        'api_tokens' => 'API token',
    ],
    'actions' => [
        'activate' => 'Kích hoạt',
        'deactivate' => 'Vô hiệu hóa',
        'manage_sessions' => 'Quản lý phiên đăng nhập',
        'revoke_sessions' => 'Thu hồi phiên trình duyệt',
    ],
    'messages' => [
        'status_updated' => 'Đã cập nhật trạng thái tài khoản.',
        'sessions_revoked' => 'Đã thu hồi các phiên đăng nhập trình duyệt.',
        'cannot_deactivate_self' => 'Bạn không thể vô hiệu hóa tài khoản của chính mình.',
        'last_super_admin' => 'Không thể xóa, vô hiệu hóa hoặc gỡ vai trò Super Admin cuối cùng.',
    ],
];
