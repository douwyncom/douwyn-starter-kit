<?php

namespace App\Enums;

enum AccountActionType: string
{
    case EMAIL_VERIFICATION = 'email_verification';
    case PASSWORD_RESET = 'password_reset';
    case EMAIL_CHANGE = 'email_change';
}
