export declare function pickSsrForwardHeaders(headers: Readonly<Record<string, string | undefined>>, sessionCookieName: string, xsrfCookieName: string): Record<string, string>;
export declare function filterCookieHeader(cookieHeader: string | undefined, allowedNames: readonly string[]): string | undefined;
export declare function cookieValue(cookieHeader: string | undefined, name: string): string | undefined;
export declare function isUnsafeMethod(method: string | undefined): boolean;
export declare function resolveCsrfURL(baseURL: string, configuredURL: string): string;
