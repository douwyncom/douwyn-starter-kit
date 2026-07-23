# API lifecycle and errors

The `/api/*` surface emits lifecycle metadata on successful and error responses.
Nuxt and mobile clients should record these headers with their own diagnostic
events, without exposing credentials or request bodies.

## Correlation and version headers

| Header | Behavior |
| --- | --- |
| `X-Request-ID` | A client-provided UUID is accepted and echoed. A UUIDv7 is generated when the value is absent or invalid. The value is available as Laravel Context key `request_id`, so it is automatically attached to application logs. |
| `X-API-Version` | The semantic contract version configured by `API_VERSION`. This is independent of the `/api/v1` routing namespace. |

Browsers may send `X-Request-ID`, and CORS exposes both response headers. A
client should create one ID per logical HTTP attempt. In particular, do not use
the mobile refresh `request_id` as the HTTP correlation ID: the refresh field is
an idempotency key and has different retry semantics.

## Deprecation and sunset

Deprecation headers are disabled by default. Configure a migration window with:

```dotenv
API_DEPRECATED=true
API_DEPRECATION_AT=2027-01-15T00:00:00Z
API_SUNSET_AT=2027-07-15T00:00:00Z
API_DEPRECATION_DOCUMENTATION_URL=https://developer.example.com/migrations/v2
```

When enabled with a valid deprecation date, responses include:

- `Deprecation: @<unix-timestamp>` following RFC 9745.
- `Sunset: <HTTP-date>` following RFC 8594, when the sunset is not earlier than
  the deprecation date.
- `Link: <...>; rel="deprecation"; type="text/html"` when a valid HTTP(S)
  documentation URL is configured.

Invalid or incomplete lifecycle dates are omitted instead of emitting a
non-standard header. Treat these headers as migration hints; they do not change
the behavior of the current response.

## Error envelope

API errors use a stable top-level `code` alongside the human-readable and
localizable `message`:

```json
{
  "message": "The given data was invalid.",
  "code": "validation_failed",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

Clients must branch on `code`, never on `message`. The HTTP status remains the
primary protocol signal. `X-Request-ID` is the support/debugging identifier.
Framework-generated JSON failures (authentication, authorization, routing,
validation, CSRF, throttling, and server failures) receive a catalogue code
automatically; an explicit domain code is never overwritten.

The canonical machine-readable catalogue is available from:

```text
GET /api/v1/meta/error-codes
```

Each entry contains `code`, its typical `http_status`, an English developer
description, and `retryable`. A retryable response must still obey
`Retry-After` when that header is present and use bounded exponential backoff.

The singleton `Douwyn\StarterKit\Api\ApiErrorCodeRegistry` is the catalogue
source used by this endpoint and by Scramble's `ApiErrorCode` schema. The root
registers every `App\Enums\ApiErrorCode` case. Private modules may register
additional snake_case domain codes during provider boot; a duplicate must have
the same status, description, and retryable flag or application boot fails.

## Catalogue

| Code | Typical status | Retryable | Meaning |
| --- | ---: | :---: | --- |
| `bad_request` | 400 | No | The request is malformed or cannot be processed as sent. |
| `authentication_required` | 401 | No | A valid session or Bearer access token is required. |
| `authorization_denied` | 403 | No | The authenticated principal is not allowed to perform this action. |
| `resource_not_found` | 404 | No | The requested API resource does not exist. |
| `method_not_allowed` | 405 | No | The HTTP method is not supported by the resource. |
| `conflict` | 409 | No | The request conflicts with current resource state. |
| `payload_too_large` | 413 | No | The request body exceeds the configured limit. |
| `unsupported_media_type` | 415 | No | The request media type is unsupported. |
| `csrf_token_mismatch` | 419 | No | A stateful request lacks a valid CSRF token. |
| `validation_failed` | 422 | No | One or more request fields failed validation. |
| `rate_limit_exceeded` | 429 | Yes | The client exceeded an applicable rate limit. |
| `internal_server_error` | 500 | No | The server could not complete the request. |
| `service_unavailable` | 503 | Yes | The service is temporarily unavailable. |
| `account_inactive` | 403 | No | The account is inactive and its access was revoked. |
| `authentication_state_changed` | 401 | No | Security state changed while credentials were being issued. |
| `reauthentication_required` | 401 | No | A refresh credential is invalid; the user must sign in again. |
| `refresh_in_progress` | 409 | Yes | Another refresh for this credential is in progress. |
| `session_revoked` | 401 | No | The browser session was revoked. |
| `stateful_frontend_required` | 403 | No | The endpoint only accepts a configured first-party frontend. |
| `token_credential_required` | 409 | No | The endpoint requires a Bearer token, not a browser session. |
| `two_factor_required` | 202 | No | Authentication must continue with the supplied 2FA challenge. |

`two_factor_required` is an actionable authentication state returned with
`202 Accepted`, not a failed HTTP request. It remains in the same catalogue so
all client control-flow codes have one source of truth.
