# Tích hợp Nuxt 4

[English canonical guide](nuxt-integration.md)

Đây là bản dịch của hướng dẫn tiếng Anh. Giữ các ví dụ, lệnh và hợp đồng API
đồng bộ với tài liệu gốc.

Starter Kit dùng một bộ business API nhưng tách credential theo loại client:

| Client | Credential | Nơi lưu |
| --- | --- | --- |
| Nuxt first-party | Sanctum stateful session | Cookie `HttpOnly`, `Secure` do Laravel quản lý |
| Mobile native | Access token + rotating refresh token | iOS Keychain / Android Keystore |
| Filament | Laravel browser session, tài khoản active có `panel.access` | Cookie quản trị |

Nuxt tuyệt đối không lưu access token, refresh token hoặc challenge token trong
`localStorage`, `sessionStorage`, Nuxt payload hay `runtimeConfig.public`.

## Topology

Stateful Sanctum yêu cầu Nuxt và Laravel cùng top-level domain:

```text
https://app.example.com  → Nuxt
https://api.example.com  → Laravel API và Filament
```

Laravel production environment:

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

Local development phải dùng nhất quán `localhost` hoặc `127.0.0.1`; stateful
domain có port, còn CORS origin có cả scheme:

```dotenv
SANCTUM_STATEFUL_DOMAINS=localhost:3000
CORS_ALLOWED_ORIGINS=http://localhost:3000
```

Nếu hai ứng dụng nằm trên hai root domain khác nhau, không dùng direct-stateful.
Khi đó triển khai Nitro BFF và giữ credential ở cookie `HttpOnly` của BFF.

## Nuxt module

Package [`packages/nuxt-api`](../packages/nuxt-api) cung cấp `useApi()`,
`useAuth()`, `useAccountLifecycle()` và `useApiMetadata()` cho Nuxt 4. Trong
monorepo có thể dùng workspace dependency sau khi build. Để dùng từ repository
Nuxt riêng, build và đóng gói package từ bản Starter Kit đã chọn:

```bash
# Tại root douwyn-starter-kit
bun install --frozen-lockfile
bun run nuxt-api:build
cd packages/nuxt-api
npm pack --pack-destination /tmp
```

Sau đó, từ repository Nuxt, cài đúng tệp tarball do lệnh trên tạo ra:

```bash
# Thay đường dẫn theo tên tệp mà npm pack in ra
bun add /tmp/douwyn-nuxt-api-0.1.0.tgz
```

Số phiên bản client độc lập với tag của ứng dụng. Không cài Git root làm npm
package: module nằm trong thư mục con và cần `dist/` được build. Chỉ dùng
`bun add @douwyn/nuxt-api` khi phiên bản cần dùng đã có trên registry mà bạn
cấu hình; release Git của Starter Kit không tự publish package lên npm.

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

Các giá trị trên không phải secret và có thể override bằng
`NUXT_PUBLIC_DOUWYN_API_BASE_URL`, `NUXT_PUBLIC_DOUWYN_API_CSRF_URL` và
`NUXT_PUBLIC_DOUWYN_API_SESSION_COOKIE_NAME`.

Module luôn:

- gửi `credentials: 'include'`, `Accept: application/json` và
  `X-Requested-With: XMLHttpRequest`;
- lấy `/sanctum/csrf-cookie` trước auth mutation;
- đọc `XSRF-TOKEN` và gắn `X-XSRF-TOKEN` cho method thay đổi dữ liệu;
- ở SSR forward `accept-language`, nhưng chỉ giữ hai cookie đúng tên:
  `sessionCookieName` và `xsrfCookieName` (`XSRF-TOKEN` mặc định);
- loại bỏ cookie giao diện, analytics, cookie quản trị khác tên hoặc cookie
  không liên quan trước khi gọi Laravel;
- không tự forward `Authorization`, `Host`, `X-Forwarded-*` hoặc header tùy ý.

`sessionCookieName` phải khớp chính xác `SESSION_COOKIE` của Laravel. Không dùng
tên cookie theo phỏng đoán vì SSR sẽ chủ động loại bỏ mọi cookie ngoài allowlist.

## Session auth với `useAuth`

Login và register phải chạy từ browser để `Set-Cookie` của Laravel đến đúng
user agent. `useAuth()` chủ động báo lỗi nếu auth mutation bị gọi trong SSR.

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
    // Chỉ giữ trong memory của component cho tới khi verify xong.
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

Recovery code dùng cùng endpoint nhưng gửi duy nhất `recovery_code`; không gửi
đồng thời `otp` và `recovery_code`.

Khởi tạo user trong SSR được hỗ trợ vì module chỉ forward session cookie:

```ts
const auth = useAuth()

await useAsyncData('current-user', () => auth.fetchUser())
```

User/profile có thể nằm trong Nuxt state; credential và challenge token thì
không. Logout dùng `await auth.logout()`.

## Business API với `useApi`

```ts
import type { AccountSecurity, ApiResponse } from '@douwyn/nuxt-api/types'

const { request, csrf } = useApi()

const security = await request<ApiResponse<AccountSecurity>>('/account/security')

// Với mutation ngoài useAuth, khởi tạo CSRF trước request đầu tiên.
await csrf()
await request('/account/security/recovery-codes/regenerate', {
  method: 'POST',
  body: {
    current_password: password,
    otp,
  },
})
```

Các type có sẵn gồm session/challenge, user/profile, account security và 2FA,
browser session, mobile token pair, refresh payload và API device session.

Browser session management dùng các opaque ID (không phải Laravel session ID):

```text
GET    /api/v1/account/security/sessions
DELETE /api/v1/account/security/sessions/others
DELETE /api/v1/account/security/sessions/{id}
DELETE /api/v1/account/security/sessions
```

Account Security API nằm dưới `/api/v1/account/security`:

- `GET /account/security`;
- `POST /account/security/two-factor/app/setup` và
  `POST /account/security/two-factor/app/confirm`;
- `POST /account/security/two-factor/email/setup`,
  `POST /account/security/two-factor/email/confirm`,
  `POST /account/security/two-factor/email/resend`,
  `POST /account/security/two-factor/email/current-code`;
- `DELETE /account/security/two-factor`;
- `POST /account/security/recovery-codes/regenerate`.

Setup token và recovery code chỉ được hiển thị/lưu tạm trong memory của UI.

## Account lifecycle

Nuxt dùng `useAccountLifecycle()` cho xác minh email, quên/reset mật khẩu và đổi
email. Composable tự khởi tạo CSRF, đồng bộ user state sau đổi email và xóa user
state sau reset mật khẩu. Response xác minh email công khai chỉ trả message để
không lộ hồ sơ; nếu người dùng đang đăng nhập, gọi `useAuth().fetchUser()` sau
xác minh để làm mới state. Token từ query string của link email chỉ
giữ trong memory đủ lâu để gửi một lần tới API; không đưa token vào Nuxt payload,
log, analytics hoặc Web Storage.

```ts
const account = useAccountLifecycle()

await account.forgotPassword({ email })

await account.resetPassword({
  token: String(route.query.token),
  password,
  password_confirmation: passwordConfirmation,
})
```

`fetchEmailStatus()` an toàn cho SSR. Các mutation còn lại chỉ chạy trên browser
để cookie CSRF/session tới đúng user agent. Email change dùng
`requestEmailChange()` và `confirmEmailChange()`; nếu 2FA đang bật, request khởi
tạo gửi thêm đúng một `otp` hoặc `recovery_code`. Mobile có thể cấu hình URL
email thành universal/app links và dùng cùng contract với Bearer token.

Danh mục error code ổn định có thể đọc bằng
`await useApiMetadata().fetchErrorCodes()`. Client phân nhánh theo `code` và HTTP
status, không phân nhánh theo message đã dịch.

## Correlation và error code

Mọi response `/api/*` có `X-Request-ID` và `X-API-Version`. Nuxt có thể tạo
một UUID cho từng HTTP attempt, gửi qua `X-Request-ID`, sau đó ghi lại giá trị
response cùng telemetry phía client. Không ghi request body, cookie hay token cùng
log chẩn đoán.

Lỗi API có `code` ổn định; UI rẽ nhánh theo `code`, còn `message` chỉ dùng
để hiển thị. Danh mục runtime nằm tại `GET /meta/error-codes`. Ví dụ,
`two_factor_required` mở màn hình challenge, `rate_limit_exceeded` tôn trọng
`Retry-After`, còn `reauthentication_required` xóa credential mobile và quay lại
màn hình đăng nhập.

HTTP correlation ID và `request_id` trong payload refresh là hai giá trị khác
nhau. Correlation ID thay đổi theo từng HTTP attempt; refresh `request_id` phải
giữ nguyên khi retry cùng một thao tác. Xem toàn bộ quy ước tại
[API lifecycle và error catalogue](api-lifecycle.md).

## Mobile separation

Mobile không dùng cookie flow của Nuxt. Native app gọi token endpoints và lưu
credential bằng secure storage của hệ điều hành:

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

Mỗi lần refresh phải thay cả access và refresh token bằng cặp mới. Native app
phải tạo một `request_id` UUID, giữ nguyên ID đó cho mọi retry của cùng request,
và ghi cặp mới vào Keychain/Keystore một cách nguyên tử rồi xóa credential cũ.
Không dùng AsyncStorage hoặc SharedPreferences dạng plain text.

Nuxt có thể dùng `GET /auth/devices` và các endpoint revoke để hiển thị/quản lý
thiết bị mobile, nhưng không bao giờ nhận refresh-token hash từ backend.

## OpenAPI và generated types

Scramble vẫn là nguồn contract chính. Tại root Starter Kit:

```bash
bun run api:spec      # packages/nuxt-api/openapi.json
bun run api:types     # packages/nuxt-api/src/openapi.ts
bun run api:generate  # chạy cả hai bước
```

Sau khi build package:

```ts
import type { paths, operations } from '@douwyn/nuxt-api/openapi'
```

Chạy `api:generate` khi route/request/resource thay đổi và kiểm tra OpenAPI diff
trong CI.

Tham khảo: [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum),
[Nuxt runtime config](https://nuxt.com/docs/4.x/guide/going-further/runtime-config),
và [Nuxt request headers](https://nuxt.com/docs/4.x/api/composables/use-request-headers).
