import type { ApiErrorCatalogueResponse } from '../../types'
import type { ApiClient } from '../composables/useApi'

export interface ApiMetadataActions {
  fetchErrorCodes(): Promise<ApiErrorCatalogueResponse>
}

export function createApiMetadataActions(api: ApiClient): ApiMetadataActions {
  return {
    fetchErrorCodes: () => api.request<ApiErrorCatalogueResponse>('/meta/error-codes'),
  }
}
