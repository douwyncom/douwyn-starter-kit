import { addImportsDir, addPlugin, addTypeTemplate, createResolver, defineNuxtModule } from '@nuxt/kit'

export interface ModuleOptions {
  /** Laravel API base including the version prefix, for example https://api.example.com/api/v1. */
  baseURL: string
  /** Absolute CSRF cookie endpoint. When empty it is derived from baseURL. */
  csrfURL: string
  /** Laravel's readable CSRF cookie name. */
  xsrfCookieName: string
  /** Laravel session cookie forwarded during SSR. Must match SESSION_COOKIE. */
  sessionCookieName: string
}

export default defineNuxtModule<ModuleOptions>({
  meta: {
    name: '@douwyn/nuxt-api',
    configKey: 'douwynApi',
    compatibility: {
      nuxt: '^4.0.0',
    },
  },
  defaults: {
    baseURL: '/api/v1',
    csrfURL: '',
    xsrfCookieName: 'XSRF-TOKEN',
    sessionCookieName: 'douwyn-starter-kit-session',
  },
  setup(options, nuxt) {
    const resolver = createResolver(import.meta.url)
    const existing = nuxt.options.runtimeConfig.public.douwynApi as Partial<ModuleOptions> | undefined

    nuxt.options.runtimeConfig.public.douwynApi = {
      baseURL: existing?.baseURL ?? options.baseURL,
      csrfURL: existing?.csrfURL ?? options.csrfURL,
      xsrfCookieName: existing?.xsrfCookieName ?? options.xsrfCookieName,
      sessionCookieName: existing?.sessionCookieName ?? options.sessionCookieName,
    }

    addPlugin(resolver.resolve('./runtime/plugin'))
    addImportsDir(resolver.resolve('./runtime/composables'))
    addTypeTemplate({
      filename: 'types/douwyn-api.d.ts',
      getContents: () => `
import type { $Fetch } from 'ofetch'

declare module '#app' {
  interface NuxtApp {
    $douwynApi: $Fetch
  }

  interface PublicRuntimeConfig {
    douwynApi: {
      baseURL: string
      csrfURL: string
      xsrfCookieName: string
      sessionCookieName: string
    }
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $douwynApi: $Fetch
  }
}

export {}
`,
    })
  },
})
