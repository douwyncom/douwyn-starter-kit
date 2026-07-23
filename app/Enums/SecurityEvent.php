<?php

namespace App\Enums;

enum SecurityEvent: string
{
    case LOGIN_SUCCEEDED = 'login_succeeded';
    case LOGIN_FAILED = 'login_failed';
    case TWO_FACTOR_CHALLENGE_ISSUED = 'two_factor_challenge_issued';
    case TWO_FACTOR_VERIFICATION_FAILED = 'two_factor_verification_failed';
    case TWO_FACTOR_VERIFIED = 'two_factor_verified';
    case REFRESH_TOKEN_REUSED = 'refresh_token_reused';
    case BROWSER_SESSION_REVOKED = 'browser_session_revoked';
    case DEVICE_SESSION_REVOKED = 'device_session_revoked';
    case PASSWORD_CHANGED = 'password_changed';
    case SECURITY_CHANGED = 'security_changed';

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $event) {
            $options[$event->value] = __("resources/activity_log.events.{$event->value}");
        }

        return $options;
    }
}
