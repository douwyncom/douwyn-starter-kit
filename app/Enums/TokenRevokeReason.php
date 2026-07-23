<?php

namespace App\Enums;

enum TokenRevokeReason: string
{
    case LOGOUT = 'logout';
    case USER_REVOKED = 'user_revoked';
    case ADMIN_REVOKED = 'admin_revoked';
    case LOGOUT_ALL = 'logout_all';
    case PASSWORD_CHANGED = 'password_changed';
    case SECURITY_CHANGED = 'security_changed';
    case ACCOUNT_INACTIVE = 'account_inactive';
    case REFRESH_TOKEN_REUSED = 'refresh_token_reused';
    case REPLACED_BY_NEW_LOGIN = 'replaced_by_new_login';
    case EXPIRED = 'expired';
}
