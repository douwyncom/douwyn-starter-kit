# @douwyn/nuxt-api

Nuxt 4 module for the Douwyn Laravel starter kit's stateful Sanctum flow.

```ts
export default defineNuxtConfig({
  modules: ['@douwyn/nuxt-api'],
  douwynApi: {
    baseURL: 'https://api.example.com/api/v1',
    csrfURL: 'https://api.example.com/sanctum/csrf-cookie',
    sessionCookieName: 'douwyn_session',
  },
})
```

The module auto-imports `useApi()`, `useAuth()`, `useAccountLifecycle()`, and
`useApiMetadata()`. It uses Laravel's HttpOnly session cookie, initializes CSRF,
and never stores credentials in browser storage. During SSR it forwards
`accept-language` and only the configured Laravel session and `XSRF-TOKEN`
cookies; unrelated browser cookies are removed.

`useAccountLifecycle()` provides typed email verification, password reset, and
verified email-change actions. Opaque link tokens are passed directly to an
action and are never persisted by the module. Email verification returns only
the server message; call `useAuth().fetchUser()` afterward when refreshing an
already authenticated UI. `useApiMetadata()` exposes the
stable error-code catalogue for diagnostics and client retry policy.

`sessionCookieName` must exactly match Laravel's `SESSION_COOKIE` value.

See [the integration guide](../../docs/nuxt-integration.md) for deployment,
2FA challenge, account-security, mobile separation, and OpenAPI generation.

## License

Apache-2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE). Commercial Douwyn
modules are separate packages and are not included with this client.

## Contact

Report bugs through **[contact@douwyn.com](mailto:contact@douwyn.com)** or
**[https://douwyn.com](https://douwyn.com)**.
