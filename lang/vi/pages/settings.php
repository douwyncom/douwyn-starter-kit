<?php

declare(strict_types=1);

return [
    'cluster' => 'Cài đặt',
    'general' => [
        'title' => 'Tổng quan',
        'subheading' => 'Cấu hình cơ bản được sử dụng trong toàn bộ ứng dụng.',
        'section_description' => 'Các cài đặt này ảnh hưởng đến toàn bộ hệ thống.',
        'action_reset_default' => 'Đặt lại mặc định',
        'site_name' => 'Tên trang web',
        'site_name_helper' => 'Hiển thị trong tiêu đề ứng dụng và email.',
        'site_description' => 'Mô tả trang web',
        'site_description_helper' => 'Khẩu hiệu ngắn cho trang đích và SEO.',
        'timezone' => 'Múi giờ',
        'timezone_helper' => 'Được sử dụng để hiển thị ngày tháng và lập lịch.',
        'locale' => 'Ngôn ngữ',
        'locale_helper' => 'Ngôn ngữ mặc định cho ứng dụng.',
        'saved' => 'Cài đặt tổng quan đã được cập nhật.',
        'reset' => 'Các giá trị đã được đặt lại. Nhấp vào Lưu thay đổi để áp dụng.',
    ],
    'media' => [
        'title' => 'Media',
        'subheading' => 'Cấu hình Media và cài đặt chuyển đổi hình ảnh.',
        'section_conversion' => 'Chuyển đổi hình ảnh',
        'section_conversion_desc' => 'Cấu hình tự động chuyển đổi hình ảnh sang các định dạng hiện đại.',
        'convert_format' => 'Định dạng chuyển đổi mặc định',
        'convert_format_helper' => 'Chỉ có thể chọn một định dạng để chuyển đổi tự động khi tải lên.',
        'formats' => [
            'none' => 'Không chuyển đổi',
            'webp' => 'WebP (Được hỗ trợ tốt)',
            'avif' => 'AVIF (Nén tốt nhất - Yêu cầu PHP GD hỗ trợ)',
        ],
        'descriptions' => [
            'none' => 'Giữ nguyên định dạng gốc của hình ảnh.',
            'webp' => 'Định dạng hình ảnh hiện đại với khả năng nén vượt trội so với JPEG và PNG.',
            'avif' => 'Định dạng hình ảnh nén hiệu quả nhất hiện nay, nhưng có thể không được hỗ trợ bởi một số trình duyệt hoặc server cũ.',
        ],
        'saved' => 'Cài đặt Media đã được lưu.',
    ],
    'save_change' => 'Lưu thay đổi',
    'saved' => 'Đã lưu cài đặt',
    'reset' => 'Đặt lại',
];
