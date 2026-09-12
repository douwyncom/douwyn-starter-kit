# Nuxt 4 integration

[Vietnamese translation](nuxt-integration.vi.md)

This English document is the canonical integration guide. Keep the Vietnamese
translation's examples, commands, and API contracts synchronized with it.

Starter Kit exposes one business API with credentials appropriate to each client:

| Client | Credential | Storage |
| --- | --- | --- |
| First-party Nuxt | Sanctum stateful session | Laravel-managed `HttpOnly`, `Secure` cookie |
| Native mobile | Access token + rotating refresh token | iOS Keychain / Android Keystore |
| Filament | Laravel session, active account with `panel.access` | Administration cookie |

Never store access tokens, refresh tokens, or challenge tokens in
`localStorage`, `sessionStorage`, Nuxt payloads, or `runtimeConfig.public`.

## Topology

Stateful Sanctum requires Nuxt and Laravel to share the same root domain:

```text
https://app.example.com  → Nuxt
https://api.example.com  → Laravel API and Filament
```

Configure Laravel in production:

```dotenv
APP_URL=https://api.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com
CORS_ALLOWED_ORIGINS=https://app.example.com
CORS_SUPPORTS_CREDENTIALS=true
SESSION_DOMAIN=.example.com
SESSION_COOKIE=douwyn_session
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

For local development, use either `localhost` or `127.0.0.1` consistently.
Stateful domains include the port; CORS origins also include the scheme:

```dotenv
SANCTUM_STATEFUL_DOMAINS=localhost:3000
CORS_ALLOWED_ORIGINS=http://localhost:3000
```

For applications on different root domains, use a separately implemented Nitro
backend-for-frontend (BFF) with credentials held in its `HttpOnly` cookie. The
direct stateful configuration below does not implement that architecture.

## Nuxt module

The [`packages/nuxt-api`](../packages/nuxt-api) package provides `useApi()`,
`useAuth()`, `useAccountLifecycle()`, and `useApiMetadata()` for Nuxt 4. A
monorepo can use a workspace dependency after building the package. To use it
in a separate Nuxt repository, build and pack the selected Starter Kit checkout:

```bash
# From the douwyn-starter-kit root
bun install --frozen-lockfile
bun run nuxt-api:build
cd packages/nuxt-api
npm pack --pack-destination /tmp
```

Then install the resulting tarball from the Nuxt repository:

```bash
# Use the actual filename printed by npm pack
bun add /tmp/douwyn-nuxt-api-0.1.0.tgz
```

The client version is independent of the application's release tag. Do not
install the Git root as an npm package: the module is in a subdirectory and
requires built `dist/` output. Use `bun add @douwyn/nuxt-api` only when the
required package version exists in your configured registry; a Starter Kit Git
release does not automatically publish the client to npm.

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@douwyn/nuxt-api'],

  douwynApi: {
    baseURL: 'https://api.example.com/api/v1',
    csrfURL: 'https://api.example.com/sanctum/csrf-cookie',
    sessionCookieName: 'douwyn_session',
  },
})
```

These values are public configuration. Override them with
`NUXT_PUBLIC_DOUWYN_API_BASE_URL`, `NUXT_PUBLIC_DOUWYN_API_CSRF_URL`, and
`NUXT_PUBLIC_DOUWYN_API_SESSION_COOKIE_NAME` as needed.

The module:

- sends `credentials: 'include'`, `Accept: application/json`, and
  `X-Requested-With: XMLHttpRequest`;
- initializes `/sanctum/csrf-cookie` before authentication mutations;
- reads `XSRF-TOKEN` and sends `X-XSRF-TOKEN` for mutating requests;
- forwards `accept-language` during SSR, but forwards only the exact configured
  `sessionCookieName` and `xsrfCookieName` cookies (`XSRF-TOKEN` by default);
- removes unrelated browser cookies, including analytics, interface settings,
  and differently named administration cookies; and
- does not automatically forward `Authorization`, `Host`, `X-Forwarded-*`, or
  arbitrary request headers.

`sessionCookieName` must exactly match Laravel's `SESSION_COOKIE`. SSR
intentionally discards cookies outside that allowlist.

## Session authentication with useAuth

Login and registration must run in the browser so Laravel's `Set-Cookie`
headers reach the user agent. `useAuth()` rejects authentication mutations
called during SSR.

```vue
<script setup lang="ts">
import type { AuthChallenge } from '@douwyn/nuxt-api/types'

const auth = useAuth()
const challenge = shallowRef<AuthChallenge | null>(null)

async function login(email: string, password: string) {
  const result = await auth.login({
    email,
    password,
    device_name: 'Nuxt Web',
  })

  if (result.status === 'challenge') {
    // Keep the challenge in component memory until verification finishes.
    challenge.value = result.challenge
    return
  }

  await navigateTo('/account')
}

async function verify(otp: string) {
  if (!challenge.value) return

  await auth.verifyChallenge({
    challenge_token: challenge.value.challenge_token,
    otp,
  })

  challenge.value = null
  await navigateTo('/account')
}
</script>
```

A recovery code uses the same endpoint with `recovery_code` instead of `otp`.
Send exactly one of those fields.

Fetching the current user during SSR is supported because the module forwards
the configured session cookie:

```ts
const auth = useAuth()

await useAsyncData('current-user', () => auth.fetchUser())
```

User/profile data may be held in Nuxt state; credentials and challenge tokens
must remain outside it. Log out with `await auth.logout()`.

## Business API with useApi

```ts
import type { AccountSecurity, ApiResponse } from '@douwyn/nuxt-api/types'

const { request, csrf } = useApi()

const security = await request<ApiResponse<AccountSecurity>>('/account/security')

// Initialize CSRF before the first mutation outside useAuth.
await csrf()
await request('/account/security/recovery-codes/regenerate', {
  method: 'POST',
  body: {
    current_password: password,
    otp,
  },
})
```

Exported types cover sessions/challenges, user profiles, account security and
2FA, browser sessions, mobile token pairs, refresh payloads, and device sessions.

Browser session management uses opaque IDs, not Laravel session IDs:

```text
GET    /api/v1/account/security/sessions
DELETE /api/v1/account/security/sessions/others
DELETE /api/v1/account/security/sessions/{id}
DELETE /api/v1/account/security/sessions
```

Account Security endpoints below are relative to `/api/v1`:

- `GET /account/security`;
- `POST /account/security/two-factor/app/setup` and
  `POST /account/security/two-factor/app/confirm`;
- `POST /account/security/two-factor/email/setup`,
  `POST /account/security/two-factor/email/confirm`,
  `POST /account/security/two-factor/email/resend`, and
  `POST /account/security/two-factor/email/current-code`;
- `DELETE /account/security/two-factor`; and
- `POST /account/security/recovery-codes/regenerate`.

Display and retain setup tokens and recovery codes only temporarily in UI memory.

## Account lifecycle

Use `useAccountLifecycle()` for email verification, forgotten/reset passwords,
and verified email changes. It initializes CSRF, synchronizes user state after
an email change, and clears user state after a password reset. Public email
verification returns only a message; if the user is signed in, call
`useAuth().fetchUser()` afterward to refresh their profile. Hold email-link query
tokens in memory only long enough to submit them; do not include them in Nuxt
payloads, logs, analytics, or Web Storage.

```ts
const account = useAccountLifecycle()

await account.forgotPassword({ email })

await account.resetPassword({
  token: String(route.query.token),
  password,
  password_confirmation: passwordConfirmation,
})
```

`fetchEmailStatus()` supports SSR. Mutations run only in the browser so CSRF
and session cookies reach the user agent. Use `requestEmailChange()` and
`confirmEmailChange()` for email changes. If 2FA is enabled, send exactly one of
`otp` or `recovery_code` when requesting the change. Mobile applications may use
universal/app links for the same email URLs and submit the contract with Bearer
credentials.

Read the stable error catalogue with
`await useApiMetadata().fetchErrorCodes()`. Branch on `code` and HTTP status,
rather than a translated message.

## Correlation and error codes

Responses under `/api/*` include `X-Request-ID` and `X-API-Version`. A Nuxt
client can generate a UUID for each HTTP attempt, send it as `X-Request-ID`,
and record the response value in client telemetry. Keep request bodies,
cookies, and tokens out of diagnostic logs.

API errors contain a stable `code`; use `message` for display. The runtime
catalogue is at `GET /meta/error-codes`, relative to the configured API base.
For example, `two_factor_required` opens the challenge UI,
`rate_limit_exceeded` respects `Retry-After`, and `reauthentication_required`
clears mobile credentials and returns the user to login.

HTTP correlation IDs and the mobile refresh payload's `request_id` have
different lifetimes: correlation IDs change for each HTTP attempt, while the
refresh ID stays the same across retries of one operation. See
[API lifecycle and errors](api-lifecycle.md).

## Mobile separation

Native applications use token endpoints and operating-system secure storage,
independently of the Nuxt cookie flow:

```text
POST   /api/v1/auth/token/login
POST   /api/v1/auth/token/register
POST   /api/v1/auth/token/challenges/verify
POST   /api/v1/auth/token/refresh
POST   /api/v1/auth/token/logout
GET    /api/v1/auth/devices
DELETE /api/v1/auth/devices/{id}
DELETE /api/v1/auth/devices
```

On refresh, replace both access and refresh tokens with the new pair. Generate
a UUID `request_id` once per refresh operation, retain it for all retries, and
atomically replace the stored pair in Keychain/Keystore. Do not store raw
credentials in AsyncStorage or plain SharedPreferences.

Nuxt may use `GET /auth/devices` and the revocation endpoints to manage mobile
devices; the API never returns refresh-token hashes. See the
[authentication contract](api-auth.md) for required payloads and replay rules.

## OpenAPI and generated types

Scramble is the canonical contract source. From the Starter Kit root:

```bash
bun run api:spec      # packages/nuxt-api/openapi.json
bun run api:types     # packages/nuxt-api/src/openapi.ts
bun run api:generate  # run both steps
```

After building the package:

```ts
import type { paths, operations } from '@douwyn/nuxt-api/openapi'
```

Run `api:generate` when routes, requests, or resources change, and review the
OpenAPI diff in CI. Generate public artifacts without commercial modules installed.

References: [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum),
[Nuxt runtime config](https://nuxt.com/docs/4.x/guide/going-further/runtime-config),
and [Nuxt request headers](https://nuxt.com/docs/4.x/api/composables/use-request-headers).
