import { useState } from '#app'
import { computed } from 'vue'
import type {
  ApiResponse,
  AuthChallenge,
  AuthResult,
  ChallengeVerifyPayload,
  SessionAuthResponse,
  SessionCredential,
  SessionLoginPayload,
  SessionRegisterPayload,
  TwoFactorRequiredResponse,
  User,
} from '../../types'
import { useApi } from './useApi'

function isTwoFactorResponse(response: SessionAuthResponse): response is TwoFactorRequiredResponse {
  return 'code' in response && response.code === 'two_factor_required'
}

function responseStatus(error: unknown): number | undefined {
  if (!error || typeof error !== 'object') {
    return undefined
  }

  const candidate = error as {
    status?: number
    statusCode?: number
    response?: { status?: number }
  }

  return candidate.response?.status ?? candidate.statusCode ?? candidate.status
}

function clientMutationOnly(): void {
  if (typeof window === 'undefined') {
    throw new Error(
      'Stateful authentication mutations must run in the browser so Laravel Set-Cookie headers reach the user agent.',
    )
  }
}

export function useAuth() {
  const api = useApi()
  const user = useState<User | null>('douwyn:auth:user', () => null)
  const initialized = useState<boolean>('douwyn:auth:initialized', () => false)

  async function fetchUser(): Promise<User | null> {
    try {
      const response = await api.request<ApiResponse<User>>('/auth/me')
      user.value = response.data
    } catch (error: unknown) {
      if (![401, 403].includes(responseStatus(error) ?? 0)) {
        throw error
      }

      user.value = null
    } finally {
      initialized.value = true
    }

    return user.value
  }

  async function login(payload: SessionLoginPayload): Promise<AuthResult> {
    clientMutationOnly()
    await api.csrf(true)

    const response = await api.request<SessionAuthResponse>('/auth/session/login', {
      method: 'POST',
      body: payload,
    })

    if (isTwoFactorResponse(response)) {
      return { status: 'challenge', challenge: response.data }
    }

    user.value = response.data.user
    initialized.value = true

    return { status: 'authenticated', user: response.data.user }
  }

  async function register(payload: SessionRegisterPayload): Promise<User> {
    clientMutationOnly()
    await api.csrf(true)

    const response = await api.request<ApiResponse<SessionCredential>>('/auth/session/register', {
      method: 'POST',
      body: payload,
    })

    user.value = response.data.user
    initialized.value = true

    return response.data.user
  }

  async function verifyChallenge(payload: ChallengeVerifyPayload): Promise<User> {
    clientMutationOnly()
    await api.csrf(true)

    const response = await api.request<ApiResponse<SessionCredential>>(
      '/auth/session/challenges/verify',
      {
        method: 'POST',
        body: payload,
      },
    )

    user.value = response.data.user
    initialized.value = true

    return response.data.user
  }

  async function logout(): Promise<void> {
    clientMutationOnly()
    await api.csrf(true)

    try {
      await api.request('/auth/session/logout', { method: 'POST' })
    } finally {
      user.value = null
      initialized.value = true
    }
  }

  function clear(): void {
    user.value = null
    initialized.value = true
  }

  return {
    user,
    initialized,
    isAuthenticated: computed(() => user.value !== null),
    fetchUser,
    login,
    register,
    verifyChallenge,
    logout,
    clear,
    refreshCsrf: () => api.csrf(true),
  }
}

export type { AuthChallenge }
