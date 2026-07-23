import type { components } from './openapi';
export type ISODateString = string;
export type TwoFactorMethod = 'none' | 'email' | 'app';
export type MobilePlatform = 'ios' | 'android';
export type DeviceStatus = 'active' | 'expired' | 'revoked' | 'compromised';
export type DeviceRevokeReason = 'logout' | 'user_revoked' | 'admin_revoked' | 'logout_all' | 'password_changed' | 'security_changed' | 'account_inactive' | 'refresh_token_reused' | 'replaced_by_new_login' | 'expired';
export interface ApiResponse<T> {
    data: T;
    message?: string;
}
export interface MessageResponse {
    message: string;
}
export interface ApiErrorResponse {
    message: string;
    code: ApiErrorCode;
    errors?: Record<string, string[]>;
}
export interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}
export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
}
export interface PaginatedResponse<T> {
    data: T[];
    links: PaginationLinks;
    meta: PaginationMeta;
}
export interface UserProfile {
    first_name: string | null;
    last_name: string | null;
    phone: string | null;
    locale: 'en' | 'vi' | string | null;
    timezone: string | null;
    avatar_url: string | null;
}
export interface User {
    uuid: string;
    email: string;
    email_verified_at: ISODateString | null;
    two_factor_enabled: boolean;
    profile: UserProfile;
    created_at: ISODateString;
    updated_at: ISODateString;
}
export interface AccountActionTokenPayload {
    token: string;
}
export interface ForgotPasswordPayload {
    email: string;
}
export interface ResetPasswordPayload extends AccountActionTokenPayload {
    password: string;
    password_confirmation: string;
}
export interface AccountEmailStatus {
    email: string;
    verified: boolean;
    verified_at: ISODateString | null;
    pending_email: string | null;
    pending_expires_at: ISODateString | null;
}
export interface RequestEmailChangePayload extends CurrentFactorConfirmation {
    email: string;
}
export type ApiErrorCode = components['schemas']['ApiErrorCode'];
export interface ApiErrorCatalogueEntry {
    code: ApiErrorCode;
    http_status: number;
    description: string;
    retryable: boolean;
}
export interface ApiErrorCatalogueResponse {
    data: ApiErrorCatalogueEntry[];
    meta: {
        api_version: string;
    };
}
export interface SessionLoginPayload {
    email: string;
    password: string;
    device_name?: string;
}
export interface SessionRegisterPayload {
    email: string;
    password: string;
    password_confirmation: string;
    first_name: string;
    last_name: string;
    device_name: string;
    locale?: 'en' | 'vi';
    timezone?: string;
}
export interface SessionCredential {
    credential_type: 'session';
    user: User;
}
export interface AuthChallenge {
    challenge_token: string;
    method: Exclude<TwoFactorMethod, 'none'>;
    recovery_available: boolean;
    expires_at: ISODateString;
}
export interface TwoFactorRequiredResponse {
    message: string;
    code: 'two_factor_required';
    data: AuthChallenge;
}
export type SessionAuthResponse = ApiResponse<SessionCredential> | TwoFactorRequiredResponse;
export interface ChallengeVerifyPayload {
    challenge_token: string;
    /** Required for a native-mobile challenge; omitted for Nuxt sessions. */
    device_id?: string;
    otp?: string;
    recovery_code?: string;
}
export type AuthResult = {
    status: 'authenticated';
    user: User;
} | {
    status: 'challenge';
    challenge: AuthChallenge;
};
export interface BrowserSession {
    id: string;
    device: string;
    ip_address: string | null;
    last_active_at: ISODateString;
    expires_at: ISODateString;
    created_at: ISODateString;
    current: boolean;
    status: 'active';
}
export interface PendingTwoFactorSetup {
    method: Exclude<TwoFactorMethod, 'none'>;
    expires_at: ISODateString;
}
export interface TwoFactorStatus {
    enabled: boolean;
    method: TwoFactorMethod;
    confirmed_at: ISODateString | null;
    enabled_at: ISODateString | null;
    recovery_codes_remaining: number;
    pending_setup?: PendingTwoFactorSetup | null;
}
export interface AccountSecurity {
    two_factor: TwoFactorStatus;
}
export interface CurrentFactorConfirmation {
    current_password: string;
    /** Required when the current factor is an authenticator or email OTP. */
    otp?: string;
    /** Alternative to otp when recovery codes are available. */
    recovery_code?: string;
}
export interface TwoFactorAppSetup extends CurrentFactorConfirmation {
}
export interface TwoFactorAppSetupData {
    setup_token: string;
    method: 'app';
    secret: string;
    otpauth_uri: string;
    expires_at: ISODateString;
}
export interface TwoFactorAppConfirmPayload {
    setup_token: string;
    otp: string;
}
export interface TwoFactorEmailSetup extends CurrentFactorConfirmation {
}
export interface TwoFactorEmailSetupData {
    setup_token: string;
    method: 'email';
    expires_at: ISODateString;
}
export interface TwoFactorEmailConfirmPayload {
    setup_token: string;
    otp: string;
}
export interface TwoFactorSetupResendPayload {
    setup_token: string;
}
export interface TwoFactorConfirmedData {
    two_factor: TwoFactorStatus;
    recovery_codes: string[];
}
export interface CurrentEmailCodePayload {
    current_password: string;
}
export interface RecoveryCodesRegeneratePayload extends CurrentFactorConfirmation {
}
export interface RecoveryCodesData {
    recovery_codes: string[];
}
export interface MobileDeviceCredentials {
    device_id: string;
    device_name: string;
    platform: MobilePlatform;
    app_version?: string;
}
export interface MobileLoginPayload extends MobileDeviceCredentials {
    email: string;
    password: string;
}
export interface MobileRegisterPayload extends MobileDeviceCredentials {
    email: string;
    password: string;
    password_confirmation: string;
    first_name: string;
    last_name: string;
    locale?: 'en' | 'vi';
    timezone?: string;
}
export interface MobileRefreshPayload {
    request_id: string;
    refresh_token: string;
    device_id: string;
    app_version?: string;
}
export interface MobileLogoutPayload {
    refresh_token: string;
    device_id: string;
}
export type MobileTokenPair = components['schemas']['MobileTokenPairResource'];
export type ApiDevice = components['schemas']['ApiDeviceSessionResource'];
