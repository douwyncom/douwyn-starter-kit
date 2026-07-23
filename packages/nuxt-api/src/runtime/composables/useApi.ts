import { useNuxtApp, useRuntimeConfig } from '#app'
import { $fetch, type FetchOptions } from 'ofetch'
import { resolveCsrfURL } from '../utils/http'

let clientCsrfRequest: Promise<void> | undefined

export interface ApiClient {
  request<T>(path: string, options?: FetchOptions<'json'>): Promise<T>
  csrf(force?: boolean): Promise<void>
}

export function useApi(): ApiClient {
  const { $douwynApi } = useNuxtApp()
  const config = useRuntimeConfig().public.douwynApi

  async function csrf(force = false): Promise<void> {
    if (typeof window === 'undefined') {
      throw new Error(
        'CSRF initialization must run in the browser. SSR reads may forward cookies, but auth mutations must be client-side.',
      )
    }

    if (force) {
      clientCsrfRequest = undefined
    }

    clientCsrfRequest ??= $fetch(resolveCsrfURL(config.baseURL, config.csrfURL), {
      credentials: 'include',
      retry: 0,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    }).then(() => undefined).catch((error: unknown) => {
      clientCsrfRequest = undefined
      throw error
    })

    return clientCsrfRequest
  }

  return {
    request: <T>(path: string, options?: FetchOptions<'json'>) => $douwynApi<T>(path, options),
    csrf,
  }
}
