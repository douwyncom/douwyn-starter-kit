<?php

declare(strict_types=1);

namespace App\Services\System;

/** Curated host behavior, maintained alongside the user and role forms and policies. */
final class SystemAccessGuide
{
    public static function forLocale(string $locale): array
    {
        $vietnamese = $locale === 'vi';

        return [
            'locale' => $locale,
            'topics' => [
                [
                    'key' => 'roles_and_permissions',
                    'title' => $vietnamese ? 'Vai trò và quyền' : 'Roles and permissions',
                    'instructions' => $vietnamese
                        ? ['Vai trò là một nhóm quyền. Quyền kiểm soát từng thao tác; tên vai trò tự nó không cấp mọi quyền.', 'Mở mục Vai trò trong trang quản trị để xem quyền của vai trò. Việc tạo hoặc sửa vai trò yêu cầu super_admin cùng quyền roles.create hoặc roles.update tương ứng.']
                        : ['A role groups permissions. Permissions control individual actions; a role name alone does not grant every permission.', 'Open Roles in the admin panel to inspect role permissions. Creating or editing a role requires super_admin plus roles.create or roles.update respectively.'],
                    'required_permissions' => ['roles.view'], 'admin_path' => '/admin/roles',
                ],
                [
                    'key' => 'assign_a_role',
                    'title' => $vietnamese ? 'Gán vai trò cho người dùng' : 'Assign a user role',
                    'instructions' => $vietnamese
                        ? ['Mở Người dùng, chọn người dùng cần sửa, chọn Vai trò rồi lưu. Cần users.view, users.update và users.assign_roles.', 'Chỉ super_admin được gán các vai trò hệ thống super_admin hoặc admin. Không được bỏ vai trò hoặc vô hiệu hóa super_admin hoạt động cuối cùng.', 'Trợ lý chỉ giải thích quy trình; mọi thay đổi phải do người có quyền thực hiện và kiểm tra trong giao diện.']
                        : ['Open Users, edit the intended user, select Roles, then save. This requires users.view, users.update and users.assign_roles.', 'Only super_admin may assign the system roles super_admin or admin. The last active super_admin cannot lose that role or be deactivated.', 'The assistant only explains the procedure; an authorized operator must make and review changes in the interface.'],
                    'required_permissions' => ['users.view', 'users.update', 'users.assign_roles'], 'admin_path' => '/admin/users',
                ],
                [
                    'key' => 'panel_and_tokens',
                    'title' => $vietnamese ? 'Truy cập quản trị và API' : 'Admin and API access',
                    'instructions' => $vietnamese
                        ? ['Tài khoản phải đang hoạt động và có panel.access để vào trang quản trị. Mỗi trang và thao tác còn kiểm quyền riêng.', 'Token abilities giới hạn thao tác API, không thay thế permission của người dùng. Cấp permission không tự mở rộng abilities của token hiện có.']
                        : ['An account must be active and have panel.access to enter the admin panel. Each page and action also checks its own permissions.', 'Token abilities restrict API actions and do not replace user permissions. Granting a permission does not automatically expand an existing token.'],
                    'required_permissions' => ['panel.access'],
                ],
            ],
        ];
    }
}
