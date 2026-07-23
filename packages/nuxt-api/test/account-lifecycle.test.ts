import { describe, expect, it } from 'bun:test'
import type { FetchOptions } from 'ofetch'
import type { ApiClient } from '../src/runtime/composables/useApi'
import { createAccountLifecycleActions } from '../src/runtime/utils/accountLifecycle'
import { createApiMetadataActions } from '../src/runtime/utils/apiMetadata'
import type {
  AccountEmailStatus,
  ApiErrorCatalogueResponse,
  ApiResponse,
  MessageResponse,
  User,
} from '../src/types'

interface RecordedRequest {
  path: string
  options?: FetchOptions<'json'>
}

function user(overrides: Partial<User> = {}): User {
  return {
    uuid: '0190f912-1111-7000-8000-111111111111',
    email: 'member@example.com',
    email_verified_at: null,
    two_factor_enabled: false,
    profile: {
      first_name: 'Starter',
      last_name: 'Member',
      phone: null,
      locale: 'vi',
      timezone: 'Asia/Ho_Chi_Minh',
      avatar_url: null,
    },
    created_at: '2026-07-12T00:00:00Z',
    updated_at: '2026-07-12T00:00:00Z',
    ...overrides,
  }
}

function fakeApi(responses: Record<string, unknown>) {
  const requests: RecordedRequest[] = []
  let csrfRequests = 0

  const api: ApiClient = {
    async request<T>(path: string, options?: FetchOptions<'json'>): Promise<T> {
      requests.push({ path, options })

      if (!(path in responses)) {
        throw new Error(`No fake response configured for ${path}`)
      }

      return responses[path] as T
    },
    async csrf(): Promise<void> {
      csrfRequests += 1
    },
  }

  return {
    api,
    requests,
    csrfRequests: () => csrfRequests,
  }
}

describe('account lifecycle actions', () => {
  it('keeps the email-status read SSR-safe and unwraps its data', async () => {
    const status: AccountEmailStatus = {
      email: 'member@example.com',
      verified: false,
      verified_at: null,
      pending_email: null,
      pending_expires_at: null,
    }
    const fake = fakeApi({
      '/account/email': { data: status } satisfies ApiResponse<AccountEmailStatus>,
    })
    let clientAssertions = 0
    const actions = createAccountLifecycleActions(
      fake.api,
      {
        setCurrentUser: () => undefined,
        clearCurrentUser: () => undefined,
      },
      () => {
        clientAssertions += 1
      },
    )

    await expect(actions.fetchEmailStatus()).resolves.toEqual(status)
    expect(fake.requests).toEqual([{ path: '/account/email', options: undefined }])
    expect(fake.csrfRequests()).toBe(0)
    expect(clientAssertions).toBe(0)
  })

  it('uses the lifecycle contracts, initializes CSRF, and synchronizes auth state', async () => {
    const originalUser = user()
    const changedUser = user({ email: 'changed@example.com' })
    const message: MessageResponse = { message: 'Accepted.' }
    const fake = fakeApi({
      '/account/email/verification-notification': message,
      '/auth/email/verify': message,
      '/auth/password/forgot': message,
      '/auth/password/reset': message,
      '/account/email/change': message,
      '/account/email/change/confirm': { data: changedUser } satisfies ApiResponse<User>,
    })
    let currentUser: User | null = originalUser
    let clientAssertions = 0
    const actions = createAccountLifecycleActions(
      fake.api,
      {
        setCurrentUser: (value) => {
          currentUser = value
        },
        clearCurrentUser: () => {
          currentUser = null
        },
      },
      () => {
        clientAssertions += 1
      },
    )

    await expect(actions.resendEmailVerification()).resolves.toBe('Accepted.')
    await expect(actions.verifyEmail({ token: 'verification-token' })).resolves.toBe('Accepted.')
    expect(currentUser).toEqual(originalUser)

    await expect(actions.forgotPassword({ email: 'member@example.com' })).resolves.toBe('Accepted.')
    await expect(actions.requestEmailChange({
      email: 'changed@example.com',
      current_password: 'Secret123',
      otp: '123456',
    })).resolves.toBe('Accepted.')
    await expect(actions.confirmEmailChange({ token: 'email-change-token' })).resolves.toEqual(changedUser)
    expect(currentUser).toEqual(changedUser)

    await expect(actions.resetPassword({
      token: 'password-reset-token',
      password: 'Changed456',
      password_confirmation: 'Changed456',
    })).resolves.toBe('Accepted.')
    expect(currentUser).toBeNull()

    expect(fake.csrfRequests()).toBe(6)
    expect(clientAssertions).toBe(6)
    expect(fake.requests.map(({ path }) => path)).toEqual([
      '/account/email/verification-notification',
      '/auth/email/verify',
      '/auth/password/forgot',
      '/account/email/change',
      '/account/email/change/confirm',
      '/auth/password/reset',
    ])
    expect(fake.requests.every(({ options }) => options?.method === 'POST')).toBeTrue()
    expect(fake.requests[3]?.options?.body).toEqual({
      email: 'changed@example.com',
      current_password: 'Secret123',
      otp: '123456',
    })
  })

  it('does not turn possession of an email-verification token into auth state', async () => {
    const currentUser = user()
    const fake = fakeApi({
      '/auth/email/verify': { message: 'Email address verified.' } satisfies MessageResponse,
    })
    let stateUser = currentUser
    const actions = createAccountLifecycleActions(
      fake.api,
      {
        setCurrentUser: (value) => {
          stateUser = value
        },
        clearCurrentUser: () => undefined,
      },
      () => undefined,
    )

    await expect(actions.verifyEmail({ token: 'verification-token' }))
      .resolves.toBe('Email address verified.')

    expect(stateUser).toEqual(currentUser)
  })

  it('stops an SSR mutation before CSRF or the API request', async () => {
    const fake = fakeApi({
      '/auth/password/forgot': { message: 'Accepted.' } satisfies MessageResponse,
    })
    const actions = createAccountLifecycleActions(
      fake.api,
      {
        setCurrentUser: () => undefined,
        clearCurrentUser: () => undefined,
      },
      () => {
        throw new Error('browser only')
      },
    )

    await expect(actions.forgotPassword({ email: 'member@example.com' })).rejects.toThrow(
      'browser only',
    )
    expect(fake.csrfRequests()).toBe(0)
    expect(fake.requests).toHaveLength(0)
  })
})

describe('API metadata actions', () => {
  it('fetches the machine-readable error catalogue without a CSRF request', async () => {
    const catalogue: ApiErrorCatalogueResponse = {
      data: [
        {
          code: 'validation_failed',
          http_status: 422,
          description: 'One or more request fields failed validation.',
          retryable: false,
        },
      ],
      meta: { api_version: '1.0.0' },
    }
    const fake = fakeApi({ '/meta/error-codes': catalogue })

    await expect(createApiMetadataActions(fake.api).fetchErrorCodes()).resolves.toEqual(catalogue)
    expect(fake.requests).toEqual([{ path: '/meta/error-codes', options: undefined }])
    expect(fake.csrfRequests()).toBe(0)
  })
})
