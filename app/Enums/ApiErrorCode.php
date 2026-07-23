<?php

namespace App\Enums;

enum ApiErrorCode: string
{
    case BAD_REQUEST = 'bad_request';
    case AUTHENTICATION_REQUIRED = 'authentication_required';
    case AUTHORIZATION_DENIED = 'authorization_denied';
    case RESOURCE_NOT_FOUND = 'resource_not_found';
    case METHOD_NOT_ALLOWED = 'method_not_allowed';
    case CONFLICT = 'conflict';
    case PAYLOAD_TOO_LARGE = 'payload_too_large';
    case UNSUPPORTED_MEDIA_TYPE = 'unsupported_media_type';
    case CSRF_TOKEN_MISMATCH = 'csrf_token_mismatch';
    case VALIDATION_FAILED = 'validation_failed';
    case RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';
    case INTERNAL_SERVER_ERROR = 'internal_server_error';
    case SERVICE_UNAVAILABLE = 'service_unavailable';

    case ACCOUNT_INACTIVE = 'account_inactive';
    case AUTHENTICATION_STATE_CHANGED = 'authentication_state_changed';
    case REAUTHENTICATION_REQUIRED = 'reauthentication_required';
    case REFRESH_IN_PROGRESS = 'refresh_in_progress';
    case SESSION_REVOKED = 'session_revoked';
    case STATEFUL_FRONTEND_REQUIRED = 'stateful_frontend_required';
    case TOKEN_CREDENTIAL_REQUIRED = 'token_credential_required';
    case TWO_FACTOR_REQUIRED = 'two_factor_required';

    public function httpStatus(): int
    {
        return match ($this) {
            self::TWO_FACTOR_REQUIRED => 202,
            self::BAD_REQUEST => 400,
            self::AUTHENTICATION_REQUIRED,
            self::AUTHENTICATION_STATE_CHANGED,
            self::REAUTHENTICATION_REQUIRED,
            self::SESSION_REVOKED => 401,
            self::AUTHORIZATION_DENIED,
            self::ACCOUNT_INACTIVE,
            self::STATEFUL_FRONTEND_REQUIRED => 403,
            self::RESOURCE_NOT_FOUND => 404,
            self::METHOD_NOT_ALLOWED => 405,
            self::CONFLICT,
            self::REFRESH_IN_PROGRESS,
            self::TOKEN_CREDENTIAL_REQUIRED => 409,
            self::PAYLOAD_TOO_LARGE => 413,
            self::UNSUPPORTED_MEDIA_TYPE => 415,
            self::CSRF_TOKEN_MISMATCH => 419,
            self::VALIDATION_FAILED => 422,
            self::RATE_LIMIT_EXCEEDED => 429,
            self::INTERNAL_SERVER_ERROR => 500,
            self::SERVICE_UNAVAILABLE => 503,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BAD_REQUEST => 'The request is malformed or cannot be processed as sent.',
            self::AUTHENTICATION_REQUIRED => 'A valid session or Bearer access token is required.',
            self::AUTHORIZATION_DENIED => 'The authenticated principal is not allowed to perform this action.',
            self::RESOURCE_NOT_FOUND => 'The requested API resource does not exist.',
            self::METHOD_NOT_ALLOWED => 'The HTTP method is not supported by the requested resource.',
            self::CONFLICT => 'The request conflicts with the current resource state.',
            self::PAYLOAD_TOO_LARGE => 'The request payload exceeds the configured size limit.',
            self::UNSUPPORTED_MEDIA_TYPE => 'The request media type is not supported.',
            self::CSRF_TOKEN_MISMATCH => 'The stateful request is missing a valid CSRF token.',
            self::VALIDATION_FAILED => 'One or more request fields failed validation.',
            self::RATE_LIMIT_EXCEEDED => 'The client has exceeded an applicable rate limit.',
            self::INTERNAL_SERVER_ERROR => 'The server failed to complete the request.',
            self::SERVICE_UNAVAILABLE => 'The service is temporarily unavailable.',
            self::ACCOUNT_INACTIVE => 'The user account is inactive and its access has been revoked.',
            self::AUTHENTICATION_STATE_CHANGED => 'Security-sensitive account state changed during authentication.',
            self::REAUTHENTICATION_REQUIRED => 'The refresh credential is no longer valid; a new login is required.',
            self::REFRESH_IN_PROGRESS => 'Another refresh request for this credential is currently in progress.',
            self::SESSION_REVOKED => 'The browser session has been revoked.',
            self::STATEFUL_FRONTEND_REQUIRED => 'The endpoint only accepts a configured first-party stateful frontend.',
            self::TOKEN_CREDENTIAL_REQUIRED => 'The endpoint requires a Bearer token instead of a browser session.',
            self::TWO_FACTOR_REQUIRED => 'Authentication must continue with a two-factor challenge.',
        };
    }

    public function retryable(): bool
    {
        return match ($this) {
            self::RATE_LIMIT_EXCEEDED,
            self::REFRESH_IN_PROGRESS,
            self::SERVICE_UNAVAILABLE => true,
            default => false,
        };
    }

    public static function forHttpStatus(int $status): self
    {
        return match ($status) {
            400 => self::BAD_REQUEST,
            401 => self::AUTHENTICATION_REQUIRED,
            403 => self::AUTHORIZATION_DENIED,
            404 => self::RESOURCE_NOT_FOUND,
            405 => self::METHOD_NOT_ALLOWED,
            409 => self::CONFLICT,
            413 => self::PAYLOAD_TOO_LARGE,
            415 => self::UNSUPPORTED_MEDIA_TYPE,
            419 => self::CSRF_TOKEN_MISMATCH,
            422 => self::VALIDATION_FAILED,
            429 => self::RATE_LIMIT_EXCEEDED,
            503 => self::SERVICE_UNAVAILABLE,
            default => $status >= 500 ? self::INTERNAL_SERVER_ERROR : self::BAD_REQUEST,
        };
    }

    /**
     * @return list<array{code: string, http_status: int, description: string, retryable: bool}>
     */
    public static function catalogue(): array
    {
        return array_map(
            fn (self $code): array => [
                'code' => $code->value,
                'http_status' => $code->httpStatus(),
                'description' => $code->description(),
                'retryable' => $code->retryable(),
            ],
            self::cases(),
        );
    }
}
