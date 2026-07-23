# Security telemetry

Security telemetry is stored in Spatie Activitylog under the dedicated
`security` log name. It gives Filament administrators a single audit stream for
Nuxt session auth, native mobile auth, legacy API tokens, and Filament login.
Telemetry is best-effort: a logging failure is reported through Laravel's
exception handler but does not replace the authentication result.

## Recorded events

| Event | Typical source |
| --- | --- |
| `login_succeeded` | Nuxt session, mobile token, legacy token, or Filament login |
| `login_failed` | Invalid credentials, inactive account, or denied Filament access |
| `two_factor_challenge_issued` | A valid first factor requires an OTP/recovery-code continuation |
| `two_factor_verification_failed` | Invalid, expired, exhausted, or mismatched challenge |
| `two_factor_verified` | OTP or recovery-code verification succeeds |
| `refresh_token_reused` | A rotated mobile refresh token is presented again |
| `browser_session_revoked` | One, other, or all Nuxt/Filament sessions are revoked |
| `device_session_revoked` | A mobile device family is logged out, replaced, expired, or revoked |
| `password_changed` | The user or an administrator changes/resets a password |
| `security_changed` | 2FA, account status, email, or another security control changes |

Properties identify the client channel (`nuxt_session`, `mobile`,
`api_token`, `filament`, or `system`) and only include context appropriate to
the event. API-originated events also carry the same `request_id` returned in
`X-Request-ID`, allowing a Dashboard incident to be correlated with application
logs without storing request bodies or credentials. Refresh-token reuse continues to dispatch the
`App\Events\RefreshTokenReused` domain event, which is the recommended hook for
adding an urgent application-specific alert listener.

## Privacy and sensitive values

Telemetry never stores passwords, OTPs, recovery codes, challenge tokens,
access tokens, refresh tokens, raw device IDs, raw Laravel session IDs, or raw
user-agent strings. Device/session identifiers, attempted identities, and user
agents are HMAC fingerprints derived with the application key. IP addresses are
stored to support incident review, so deployments must include them in their
privacy and retention policy.

The scheduled `activitylog:clean --force` job runs weekly. The default Activity
Log retention is 365 days in `config/activitylog.php`; change that value to meet
the deployment's legal and operational requirements.

## Filament monitoring

The Dashboard includes:

- seven-day totals for failed login, failed 2FA, refresh-token reuse, and
  browser/mobile revocations;
- a filterable recent-security-events table with user, channel/reason, IP
  address, and timestamp.

Filament also provides a dedicated global Login Sessions resource under User
Management. It uses `login_sessions.view` for access and
`login_sessions.revoke` for row/bulk revocation. Session identifiers exposed to
Livewire are opaque HMAC identifiers; raw Laravel session IDs are never rendered
in the table. The current Filament session is visible for context but cannot be
revoked from the global table.

Both widgets and the Activity Log resource require `activity_logs.view`. The
starter-kit installer grants this permission to Admin and Super Admin roles,
but not Staff. Keep `panel.access` as the independent gate for entering
Filament.

For alerting, consume high-signal events asynchronously and add deduplication
and cooldown windows. Recommended first alerts are refresh-token reuse, a new
mobile device, and repeated 2FA failures. Avoid sending an alert for every
failed password attempt because it creates noise and enables notification
abuse.
