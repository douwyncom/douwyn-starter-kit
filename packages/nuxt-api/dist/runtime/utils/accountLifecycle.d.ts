import type { AccountActionTokenPayload, AccountEmailStatus, ForgotPasswordPayload, RequestEmailChangePayload, ResetPasswordPayload, User } from '../../types';
import type { ApiClient } from '../composables/useApi';
export interface AccountLifecycleState {
    setCurrentUser(user: User): void;
    clearCurrentUser(): void;
}
export interface AccountLifecycleActions {
    fetchEmailStatus(): Promise<AccountEmailStatus>;
    resendEmailVerification(): Promise<string>;
    verifyEmail(payload: AccountActionTokenPayload): Promise<string>;
    forgotPassword(payload: ForgotPasswordPayload): Promise<string>;
    resetPassword(payload: ResetPasswordPayload): Promise<string>;
    requestEmailChange(payload: RequestEmailChangePayload): Promise<string>;
    confirmEmailChange(payload: AccountActionTokenPayload): Promise<User>;
}
export declare function createAccountLifecycleActions(api: ApiClient, state: AccountLifecycleState, ensureClientMutation: () => void): AccountLifecycleActions;
