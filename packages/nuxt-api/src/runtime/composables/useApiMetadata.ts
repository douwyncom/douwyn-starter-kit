import { createApiMetadataActions } from '../utils/apiMetadata'
import { useApi } from './useApi'

export function useApiMetadata() {
  return createApiMetadataActions(useApi())
}
