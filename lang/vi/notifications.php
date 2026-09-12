<?php

declare(strict_types=1);

return [
    'verify_email' => [
        'subject' => 'Xác minh địa chỉ email của bạn',
        'intro' => 'Hãy xác nhận địa chỉ email này thuộc về tài khoản của bạn.',
        'action' => 'Xác minh địa chỉ email',
        'outro' => 'Nếu bạn không tạo tài khoản này, bạn có thể bỏ qua email.',
    ],
    'reset_password' => [
        'subject' => 'Đặt lại mật khẩu của bạn',
        'intro' => 'Chúng tôi đã nhận được yêu cầu đặt lại mật khẩu tài khoản của bạn.',
        'action' => 'Đặt lại mật khẩu',
        'outro' => 'Nếu bạn không yêu cầu đặt lại mật khẩu, bạn có thể bỏ qua email.',
    ],
    'confirm_email_change' => [
        'subject' => 'Xác nhận địa chỉ email mới của bạn',
        'intro' => 'Hãy xác nhận địa chỉ này để hoàn tất thay đổi email tài khoản.',
        'action' => 'Xác nhận thay đổi email',
        'outro' => 'Nếu bạn không yêu cầu thay đổi này, hãy bảo vệ tài khoản của bạn ngay lập tức.',
    ],
    'two_factor' => [
        'subject' => 'Mã xác minh của bạn',
        'body' => "Mã xác minh của bạn là: :code\n\nMã này sẽ hết hạn sau :minutes phút.",
    ],
];
