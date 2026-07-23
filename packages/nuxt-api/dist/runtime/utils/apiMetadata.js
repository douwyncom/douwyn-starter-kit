export function createApiMetadataActions(api) {
  return {
    fetchErrorCodes: () => api.request("/meta/error-codes")
  };
}
