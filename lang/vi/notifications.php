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
        'preheader' => 'Mã xác minh dùng một lần của bạn có hiệu lực trong :minutes phút.',
        'eyebrow' => 'Bảo mật tài khoản',
        'title' => 'Xác minh danh tính của bạn.',
        'code_label' => 'Mã xác minh',
        'expires' => 'Hiệu lực :minutes phút',
        'one_time' => 'Mã chỉ dùng một lần. Nhập mã tại màn hình xác minh đang mở.',
        'security_title' => 'Giữ mã này cho riêng bạn.',
        'security_body' => 'Không chia sẻ mã với bất kỳ ai, kể cả người tự xưng là nhân viên hỗ trợ. Chúng tôi không bao giờ yêu cầu bạn gửi mã qua email, điện thoại hoặc tin nhắn.',
        'not_requested' => 'Nếu bạn không thực hiện yêu cầu này, hãy bỏ qua email. Mã sẽ tự hết hạn.',
        'help' => 'Cần trợ giúp?',
        'automated' => 'Email được gửi tự động để bảo vệ tài khoản của bạn.',
        'contexts' => [
            'login' => 'Xác nhận đăng nhập',
            'setup' => 'Thiết lập xác thực hai yếu tố',
            'security' => 'Xác nhận thao tác bảo mật',
            'verification' => 'Xác minh tài khoản',
        ],
        'intros' => [
            'login' => 'Chúng tôi đã nhận được yêu cầu đăng nhập vào tài khoản của bạn. Sử dụng mã bên dưới để tiếp tục.',
            'setup' => 'Bạn đang thiết lập xác thực hai yếu tố qua email. Sử dụng mã bên dưới để xác nhận phương thức bảo mật này.',
            'security' => 'Một thao tác bảo mật cần được xác nhận trên tài khoản của bạn. Sử dụng mã bên dưới để tiếp tục yêu cầu đang thực hiện.',
            'verification' => 'Sử dụng mã bên dưới để hoàn tất yêu cầu xác minh tài khoản của bạn.',
        ],
    ],
];
