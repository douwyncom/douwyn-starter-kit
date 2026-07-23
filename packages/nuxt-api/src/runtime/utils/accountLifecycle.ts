import type {
  AccountActionTokenPayload,
  AccountEmailStatus,
  ApiResponse,
  ForgotPasswordPayload,
  MessageResponse,
  RequestEmailChangePayload,
  ResetPasswordPayload,
  User,
} from '../../types'
import type { ApiClient } from '../composables/useApi'

export interface AccountLifecycleState {
  setCurrentUser(user: User): void
  clearCurrentUser(): void
}

export interface AccountLifecycleActions {
  fetchEmailStatus(): Promise<AccountEmailStatus>
  resendEmailVerification(): Promise<string>
  verifyEmail(payload: AccountActionTokenPayload): Promise<string>
  forgotPassword(payload: ForgotPasswordPayload): Promise<string>
  resetPassword(payload: ResetPasswordPayload): Promise<string>
  requestEmailChange(payload: RequestEmailChangePayload): Promise<string>
  confirmEmailChange(payload: AccountActionTokenPayload): Promise<User>
}

export function createAccountLifecycleActions(
  api: ApiClient,
  state: AccountLifecycleState,
  ensureClientMutation: () => void,
): AccountLifecycleActions {
  async function prepareMutation(): Promise<void> {
    ensureClientMutation()
    await api.csrf()
  }

  async function fetchEmailStatus(): Promise<AccountEmailStatus> {
    const response = await api.request<ApiResponse<AccountEmailStatus>>('/account/email')

    return response.data
  }

  async function resendEmailVerification(): Promise<string> {
    await prepareMutation()

    const response = await api.request<MessageResponse>(
      '/account/email/verification-notification',
      { method: 'POST' },
    )

    return response.message
  }

  async function verifyEmail(payload: AccountActionTokenPayload): Promise<string> {
    await prepareMutation()

    const response = await api.request<MessageResponse>('/auth/email/verify', {
      method: 'POST',
      body: payload,
    })

    return response.message
  }

  async function forgotPassword(payload: ForgotPasswordPayload): Promise<string> {
    await prepareMutation()

    const response = await api.request<MessageResponse>('/auth/password/forgot', {
      method: 'POST',
      body: payload,
    })

    return response.message
  }

  async function resetPassword(payload: ResetPasswordPayload): Promise<string> {
    await prepareMutation()

    const response = await api.request<MessageResponse>('/auth/password/reset', {
      method: 'POST',
      body: payload,
    })

    state.clearCurrentUser()

    return response.message
  }

  async function requestEmailChange(payload: RequestEmailChangePayload): Promise<string> {
    await prepareMutation()

    const response = await api.request<MessageResponse>('/account/email/change', {
      method: 'POST',
      body: payload,
    })

    return response.message
  }

  async function confirmEmailChange(payload: AccountActionTokenPayload): Promise<User> {
    await prepareMutation()

    const response = await api.request<ApiResponse<User>>('/account/email/change/confirm', {
      method: 'POST',
      body: payload,
    })

    state.setCurrentUser(response.data)

    return response.data
  }

  return {
    fetchEmailStatus,
    resendEmailVerification,
    verifyEmail,
    forgotPassword,
    resetPassword,
    requestEmailChange,
    confirmEmailChange,
  }
}
