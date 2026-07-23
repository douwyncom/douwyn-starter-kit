import { defineNuxtPlugin, useRequestHeaders, useRuntimeConfig } from '#app'
import { $fetch } from 'ofetch'
import { cookieValue, isUnsafeMethod, pickSsrForwardHeaders } from './utils/http'

export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig().public.douwynApi
  const isServer = typeof window === 'undefined'
  const forwardedHeaders = isServer
    ? pickSsrForwardHeaders(
        useRequestHeaders(['cookie', 'accept-language']),
        config.sessionCookieName,
        config.xsrfCookieName,
      )
    : {}

  const api = $fetch.create({
    baseURL: config.baseURL,
    credentials: 'include',
    retry: 0,
    headers: forwardedHeaders,
    onRequest({ options }) {
      const headers = new Headers(options.headers)

      if (!headers.has('Accept')) {
        headers.set('Accept', 'application/json')
      }

      if (!headers.has('X-Requested-With')) {
        headers.set('X-Requested-With', 'XMLHttpRequest')
      }

      if (isUnsafeMethod(String(options.method ?? 'GET')) && !headers.has('X-XSRF-TOKEN')) {
        const cookieHeader = isServer ? forwardedHeaders.cookie : document.cookie
        const token = cookieValue(cookieHeader, config.xsrfCookieName)

        if (token) {
          headers.set('X-XSRF-TOKEN', token)
        }
      }

      options.headers = headers
    },
  })

  return {
    provide: {
      douwynApi: api,
    },
  }
})
