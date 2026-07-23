import { createApiMetadataActions } from "../utils/apiMetadata.js";
import { useApi } from "./useApi.js";
export function useApiMetadata() {
  return createApiMetadataActions(useApi());
}
