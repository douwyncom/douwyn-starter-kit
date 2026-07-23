import type { $Fetch } from 'ofetch'

declare module '#app' {
  interface Ref<T> {
    value: T
  }

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

  interface RuntimeConfig {
    public: PublicRuntimeConfig
  }

  export function defineNuxtPlugin(
    plugin: () => { provide: { douwynApi: $Fetch } },
  ): unknown
  export function useNuxtApp(): NuxtApp
  export function useRequestHeaders(include?: string[]): Record<string, string | undefined>
  export function useRuntimeConfig(): RuntimeConfig
  export function useState<T>(key: string, init: () => T): Ref<T>
}

export {}
