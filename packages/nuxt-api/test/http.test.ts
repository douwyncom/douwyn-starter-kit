import { describe, expect, it } from 'bun:test'
import {
  cookieValue,
  filterCookieHeader,
  isUnsafeMethod,
  pickSsrForwardHeaders,
  resolveCsrfURL,
} from '../src/runtime/utils/http'

describe('SSR header forwarding', () => {
  it('forwards only the configured session, xsrf cookie, and accept-language', () => {
    expect(pickSsrForwardHeaders({
      Cookie: [
        'theme=dark',
        '_ga=analytics',
        'admin_session=must-not-leak',
        'douwyn_session=encrypted%3D%3D',
        'XSRF-TOKEN=xsrf-value',
      ].join('; '),
      'Accept-Language': 'vi-VN,vi;q=0.9',
      Authorization: 'Bearer must-not-leak',
      Host: 'app.example.com',
      'X-Forwarded-For': '203.0.113.10',
    }, 'douwyn_session', 'XSRF-TOKEN')).toEqual({
      cookie: 'douwyn_session=encrypted%3D%3D; XSRF-TOKEN=xsrf-value',
      'accept-language': 'vi-VN,vi;q=0.9',
    })
  })

  it('omits cookie when the incoming request has no configured auth cookies', () => {
    expect(pickSsrForwardHeaders({
      cookie: 'theme=dark; analytics_id=123',
      'accept-language': 'en',
    }, 'douwyn_session', 'XSRF-TOKEN')).toEqual({
      'accept-language': 'en',
    })
  })

  it('keeps only the first exact match for every allowed cookie name', () => {
    expect(filterCookieHeader(
      'douwyn_session=first; douwyn_session=second; xsrf-token=wrong-case; XSRF-TOKEN=valid',
      ['douwyn_session', 'XSRF-TOKEN'],
    )).toBe('douwyn_session=first; XSRF-TOKEN=valid')
  })
})

describe('XSRF handling', () => {
  it('reads and URL-decodes the configured cookie without truncating equals signs', () => {
    expect(cookieValue(
      'theme=dark; XSRF-TOKEN=encrypted%3D%3D; laravel_session=session-value',
      'XSRF-TOKEN',
    )).toBe('encrypted==')
  })

  it('adds XSRF only to mutating methods', () => {
    expect(isUnsafeMethod(undefined)).toBeFalse()
    expect(isUnsafeMethod('GET')).toBeFalse()
    expect(isUnsafeMethod('HEAD')).toBeFalse()
    expect(isUnsafeMethod('post')).toBeTrue()
    expect(isUnsafeMethod('DELETE')).toBeTrue()
  })

  it('derives the csrf endpoint from an absolute API URL', () => {
    expect(resolveCsrfURL('https://api.example.com/api/v1', ''))
      .toBe('https://api.example.com/sanctum/csrf-cookie')
    expect(resolveCsrfURL('/api/v1', '')).toBe('/sanctum/csrf-cookie')
    expect(resolveCsrfURL('/api/v1', 'https://edge.example.com/csrf'))
      .toBe('https://edge.example.com/csrf')
  })
})

describe('credential storage policy', () => {
  it('does not persist auth credentials in browser storage', async () => {
    const sources = await Promise.all([
      Bun.file(new URL('../src/runtime/plugin.ts', import.meta.url)).text(),
      Bun.file(new URL('../src/runtime/composables/useApi.ts', import.meta.url)).text(),
      Bun.file(new URL('../src/runtime/composables/useAuth.ts', import.meta.url)).text(),
      Bun.file(new URL('../src/runtime/composables/useAccountLifecycle.ts', import.meta.url)).text(),
      Bun.file(new URL('../src/runtime/utils/accountLifecycle.ts', import.meta.url)).text(),
    ])

    expect(sources.join('\n')).not.toContain('localStorage')
    expect(sources.join('\n')).not.toContain('sessionStorage')
  })
})
