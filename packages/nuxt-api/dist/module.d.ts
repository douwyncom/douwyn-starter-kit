export interface ModuleOptions {
    /** Laravel API base including the version prefix, for example https://api.example.com/api/v1. */
    baseURL: string;
    /** Absolute CSRF cookie endpoint. When empty it is derived from baseURL. */
    csrfURL: string;
    /** Laravel's readable CSRF cookie name. */
    xsrfCookieName: string;
    /** Laravel session cookie forwarded during SSR. Must match SESSION_COOKIE. */
    sessionCookieName: string;
}
declare const _default: import("@nuxt/schema").NuxtModule<ModuleOptions, ModuleOptions, false>;
export default _default;
