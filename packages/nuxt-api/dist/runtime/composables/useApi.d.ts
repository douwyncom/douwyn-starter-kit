import { type FetchOptions } from 'ofetch';
export interface ApiClient {
    request<T>(path: string, options?: FetchOptions<'json'>): Promise<T>;
    csrf(force?: boolean): Promise<void>;
}
export declare function useApi(): ApiClient;
