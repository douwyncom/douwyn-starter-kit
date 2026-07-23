import type { ApiErrorCatalogueResponse } from '../../types';
import type { ApiClient } from '../composables/useApi';
export interface ApiMetadataActions {
    fetchErrorCodes(): Promise<ApiErrorCatalogueResponse>;
}
export declare function createApiMetadataActions(api: ApiClient): ApiMetadataActions;
